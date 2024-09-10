<?php namespace ProcessWire;
class IndexNow extends WireData implements Module, ConfigurableModule {
  private $timeFuncs = [];

  /**
   * Construct
   */
  public function __construct() {
    parent::__construct();
    $this->urisPerCron = 9000;
    $this->indexNowKey;
    $this->indexNowKeyGenerate;
    $this->gracePeriod = 10*60;
    $this->daysToDeleteOldEntries = 10;
    $this->indexNowTimefunc = 'every30Minutes';
    $this->indexNowAllowedTemplates = [];

    $this -> timeFuncs = [
      'every30Seconds' => _('every 30 seconds'),
      'everyMinute' => _('every minute'),	
      'every2Minutes' => _('every 2 minutes'),
      'every3Minutes' => _('every 3 minutes'),
      'every4Minutes' => _('every 4 minutes'),
      'every5Minutes' => _('every 5 minutes'),
      'every10Minutes' => _('every 10 minutes'),
      'every15Minutes' => _('every 15 minutes'),
      'every30Minutes' => _('every 30 minutes'),
      'every45Minutes' => _('every 45 minutes'),
      'everyHour' => _('every hour'),
      'every2Hours' => _('every 2 hours'),
      'every4Hours' => _('every 4 hours'),
      'every6Hours' => _('every 6 hours'),
      'every12Hours' => _('every 12 hours'),
      'everyDay' => _('every day'),
      'every2Days' => _('every 2 days'),	
      'every4Days' => _('every 4 days'),
      'everyWeek' => _('every week'),
      'every2Weeks' => _('every 2 weeks'),
      'every4Weeks' => _('every 4 weeks')
    ];
  }
  
  /**
   * Ready method. Sets hooks.
   */
  public function ready() {
    $this->pages->addHookAfter('Pages::saved',   $this, 'processPageUpdate');
    $this->pages->addHookAfter('Pages::deleted', $this, 'processPageUpdate');
    $this->pages->addHookAfter('Pages::added',   $this, 'processPageUpdate');
    $this->pages->addHookAfter('Pages::new',     $this, 'processPageUpdate');
     
    if($this->indexNowTimefunc) {
      $this->addHookAfter('LazyCron::'.$this -> indexNowTimefunc, $this, 'processCron');
    }
  }

  /*
   * Process the cron. Writes log entries if any errors occur.
   */
  public function processCron() {
    $json = [];
    $ids = [];

    $database = $this->wire()->database;

    $sql = '
      SELECT
        id,
        hostname,
        url
      FROM
        index_now
      WHERE
        (
          response IS NULL OR
          response > 202
        ) AND
        saved < (NOW() - INTERVAL :gracePeriod SECOND)
      ORDER BY
        saved DESC
      LIMIT
        :limit
    ';

    $stmt = $this->wire()->database->prepare($sql);

    $stmt->bindValue(':limit', $this->get('urisPerCron'), \PDO::PARAM_INT); 
    $stmt->bindValue(':gracePeriod', $this->get('gracePeriod'), \PDO::PARAM_INT);

    $stmt->execute();

    while ($row = $stmt->fetch()) {
      if(!isset($json[$row['hostname']])) {
        $json[$row['hostname']] = [
          'host'    => $row['hostname'],
          'key'     => $this->get('indexNowKey'),
          'urlList' => []
        ];
        $ids[$row['hostname']] = [];
      }

      $json[$row['hostname']]['urlList'][] = $row['url'];
      $ids[$row['hostname']][] = $row['id'];
    }

    foreach($json as $hostname => $jsonHostname) {
      if(!$fc = file_get_contents($hostname.'/'.$jsonHostname['key'].'.txt')) {
        $this->wire()->log(_(sprintf('[IndexNow Cron %s] IndexNow key file does not exist.', $hostname)));
        continue;
      }
      if($fc !== $jsonHostname['key']) {
        $this->wire()->log(_(sprintf('[IndexNow Cron %s] IndexNow key file content does not match specification.', $hostname)));
        continue;
      }

      if(sizeof($jsonHostname['urlList']) !== 0) {
        try {
          $ch = curl_init();
  
          curl_setopt($ch, CURLOPT_URL, 'https://api.indexnow.org/indexnow');
          curl_setopt($ch, CURLOPT_POST, 1);
          curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json; charset=utf-8'
          ));
          curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonHostname));
          curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
          curl_setopt($ch, CURLOPT_HEADER, 1);
  
          $response = curl_exec($ch);
        }
        catch(\Exception $e) {
          $this->wire()->log(_(sprintf('[IndexNow Cron %s] Curl-Error: %s', $hostname, $e->getMessage())));
          return;
        }
  
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
  
        // Binding if IN is tricky... See https://phpdelusions.net/pdo#in
        $params = [':response' => $http_status];
        $in = '';
        $i = 0;
        $in_params = [];
  
        foreach ($ids[$hostname] as $item) {
            $key = ':id'.$i++;
            $in .= ($in ? ',' : '') . $key; // :id0,:id1,:id2
            $in_params[$key] = $item; // collecting values into a key-value array
        }
        $in = rtrim($in, ','); // :id0,:id1,:id2
  
        // Update database
        try {
          $sql = '
            UPDATE
              index_now
            SET
              response = :response,
              submitted = CURRENT_TIMESTAMP
            WHERE
              id IN ('.$in.')
          ';
  
          $query = $this->wire()->database->prepare($sql);
          $query->execute(array_merge($params, $in_params));
  
        } catch(\Exception $e) {
          $this->wire()->log(_(sprintf('[IndexNow Cron %s] Database-Error: %s', $hostname, $e->getMessage())));
          return;
        }
      }
    }

    // cleanup old entries
    try {
      $sql = '
        DELETE FROM
          index_now
        WHERE
          response IN (200, 202) AND
          submitted < (NOW() - INTERVAL :daysToDeleteOldEntries DAY)
      ';

      $stmt = $this->wire()->database->prepare($sql);
      $stmt->bindValue(':daysToDeleteOldEntries', $this->get('daysToDeleteOldEntries'), \PDO::PARAM_INT);
      $stmt->execute();
    } catch(\Exception $e) {
      $this->wire()->log(_(sprintf('[IndexNow Cron %s] Database-Cleanup-Error: %s', $hostname, $e->getMessage())));
      return;
    }
  }
  
  /**
   * Process page update and deletion
   * 
   * @param HookEvent $event The event object
   */
  public function processPageUpdate($event) {
    $page = $event->arguments(0);

    if(
      !in_array($page -> template -> name, $this -> get('indexNowAllowedTemplates')) // not allowed template
      OR $page->rootParent()->id === 2 // admin page)
      OR $page->isHidden() // hidden page
      OR $page->isUnpublished() // unpublished page
      OR !$page->isPublic() // public and viewable by all
    ) {
      return;
    }

    foreach($page -> urls([
      'http' => true,
      'past' => false,
      'languages' => true
    ]) as $uri) { 
      try {
        $sql = '
          INSERT INTO index_now (
            pages_id,
            hostname,
            url,
            saved
          )
          VALUES(
            :pageid,
            :hostname,
            :url,
            CURRENT_TIMESTAMP
          )
          ON DUPLICATE KEY UPDATE
            saved = CURRENT_TIMESTAMP,
            response = null,
            submitted = 0
        ';

        $query = $this->wire()->database->prepare($sql);

        $query->bindValue(':pageid',    $page->id, \PDO::PARAM_INT);
        $query->bindValue(':hostname',  parse_url($uri, PHP_URL_SCHEME).'://'.parse_url($uri, PHP_URL_HOST), \PDO::PARAM_STR);
        $query->bindValue(':url',       $uri, \PDO::PARAM_STR);

        $query->execute();
      } catch(\Exception $e) {
        $this->error($e->getMessage());
      }
    }
  }

  /**
   * Generate a new IndexNow key
   * Source for the function: https://stackoverflow.com/a/15875555
   * 
   * @return string The new key
   */
  private function generateIndexNowKey() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
      
    //$uuid = implode('', explode('-', vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4))));
    $uuid = vsprintf('%s%s%s%s%s%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
  }

  /**
   * Check if the IndexNow key file exists and is valid. If it doesn't exist, create it.
   * 
   * @param string $indexNowKey The IndexNow key
   * 
   * @return bool True if the file exists and is valid, false otherwise
   */
  public function checkAndWriteFile() {
    // avoid calling it twice when saving settings
    /*
    if($_SERVER['REQUEST_METHOD'] === 'POST') {
      return;
    }
    */

    $indexNowKey = $this->get('indexNowKey');
    if(empty($indexNowKey)) {
      $this->error(_('Your IndexNow key is not defined. Please set it in the module settings.'));
      return FALSE;
    }

    $indexNowKeyFile = $this->wire::getRootPath().$indexNowKey.'.txt';
    if(file_exists($indexNowKeyFile)) {
      if(!$fp = fopen($indexNowKeyFile, 'r')) {
        $this->error(_('Your IndexNow key file can\'t be opened. Check if it exists. Path: '.$indexNowKeyFile));
        return FALSE;
      }

      $contents = fread($fp, filesize($indexNowKeyFile));
      if($contents !== $indexNowKey) {
        $this->error(_('Your IndexNow key file exists but has the wrong content. Please check the content. Path: '.$indexNowKeyFile));
        return FALSE;
      }

      return TRUE;
    }

    if(!is_writable($indexNowKeyFile)) {
      $this->error(_('The path of your IndexNow key file can\'t be written. Please create it manually. Path: '.$indexNowKeyFile));
      return FALSE;
    }

    if(!$fp = fopen($indexNowKeyFile, 'w')) {
      $this->error(_('Your IndexNow key file can\'t be opened to write. Please create it manually. Path: '.$indexNowKeyFile));
      return FALSE;
    }
      
    if (fwrite($fp, $indexNowKey) === FALSE) {
      $this->error(_('Your IndexNow key file can\'t be written. Please create it manually. Path: '.$indexNowKeyFile));
      return FALSE;
    }
  
    fclose($fp);
    return TRUE;
  }
  
  /**
   * Get the module config inputfields
   */
  public function getModuleConfigInputfields($inputfields) {
    $modules = $this->wire()->modules;

    //$this->processCron();

    // TODO does not work
    /*
    if(
      !empty($this->indexNowKey) &&
      $this->indexNowKeyGenerate === 1
    ) {
      $this->set('indexNowKey', $this->generateIndexNowKey());
      $this->message(_('Your IndexNow key has been generated: '.$this->get('indexNowKey')));
      $modules -> saveConfig('IndexNow', 'indexNowKey', $this->get('indexNowKey'));

      $this->set('indexNowKeyGenerate', 0);
      $modules -> saveConfig('IndexNow', 'indexNowKeyGenerate', $this->get('indexNowKeyGenerate'));
    }
    else {
      //if(!empty($this->indexNowKey)) {
      $this->checkAndWriteFile();
    }
    */

    $this->checkAndWriteFile();

    /*
    if($_SERVER['REQUEST_METHOD'] === 'GET') {
      if(!empty($this->get('indexNowKey'))) {
        $this->checkAndWriteFile();
      }
      else {
        $this->warning(_('Your IndexNow key is not defined. Please set it in the module settings.'));
      }
    }
*/

    /** @var \ProcessWire\InputfieldFieldset $fs */
    $fs = $modules->get('InputfieldFieldset');
    $fs->label = $this->_('IndexNow settings');

    $inputfields->add($fs);
    
    /*
    if(empty($this -> indexNowKey)) {
      /** @var InputfieldCheckbox $f *-/
      $f = $modules->get('InputfieldCheckbox');
      $f_name = 'indexNowKeyGenerate';
      $f->name = $f_name;
      $f->label = $this->_('Generate an IndexNow key');
      $f->detail = $this->_('Either get an IndexNow key from IndexNow or let the module generate one.');
      $f->inputType = 'checkbox';
      $f->value = false;
      $f->columnWidth = 100;
      $fs->add($f);
    }
    */

    /** @var InputfieldText $f */
    $f = $modules->get('InputfieldText');
    $f_name = 'indexNowKey';
    $f->name = $f_name;
    $f->label = $this->_('Your IndexNow key');
    $f->detail = $this->_('If you already have an IndexNow key enter it here or let the module generate one, see above.');
    $f->inputType = 'text';
    $f->value = $this -> get('indexNowKey');
    $f->minlength = 8;
    $f->maxlength = 128;
    $f->columnWidth = 50;
    $fs->add($f);


    /** @var InputfieldCheckboxes $f */
    $f = $modules->get('InputfieldCheckboxes');
    $f_name = 'indexNowAllowedTemplates';
    $f->name = $f_name;
    $f->label = $this->_('Allowed Templates'); 
    $f->detail = $this->_('Select the page templates which should be sent to IndexNow. Shows label and name of templates, number of pages using this template is shown in parentheses. System templates are excluded.');
    
    foreach($this->wire()->templates as $template) {
      if($template->flags === Field::flagSystem) {
        continue;
      }
      // Icon
      // (!is_null($template->icon) ? wireIconMarkup($template->icon) : null).
      $f->addOption($template->name, $template->label.' / '.$template->name.' ('.$template->getNumPages().')');
    }
    
    $f->value = $this -> get('indexNowAllowedTemplates');
    $f->columnWidth = 50;
    $fs->add($f);

    /** @var InputfieldSelect $f */
    $f = $modules->get('InputfieldSelect');
    $f_name = 'indexNowTimefunc';
    $f->name = $f_name;
    $f->label = $this->_('Timing schedule of cron'); 
    $f->detail = $this->_('How often should should new/changed/deleted URIs be submitted to IndexNow? The value "Never" disables the cron. If you send too much changes to IndexNow, you might get an error "429 Too Many Requests" from the IndexNow API.');
    $f->addOption(null, $this->_('Never'));
    foreach($this -> timeFuncs as $key => $val) {
      $f->addOption($key, $val);
    }
    $f->value = $this -> get('indexNowTimefunc');
    $f->columnWidth = 25;
    $fs->add($f);

    /** @var InputfieldInteger $f */
    $f = $modules->get('InputfieldInteger');
    $f_name = 'urisPerCron';
    $f->name = $f_name;
    $f->label = $this->_('URIs per execution');
    $f->detail = $this->_('Number of URIs which are sent to IndexNow during one LazyCron execution. IndexNow accepts not more than 10.000 per batch.');
    $f->inputType = 'number';
    $f->min = 1;
    $f->max = 10000;
    $f->value = $this -> urisPerCron ?: 9000;
    $f->columnWidth = 25;
    $fs->add($f);

    /** @var InputfieldInteger $f */
    $f = $modules->get('InputfieldInteger');
    $f_name = 'gracePeriod';
    $f->name = $f_name;
    $f->label = $this->_('Grace period after saving');
    $f->detail = $this->_('How many seconds should the module wait after saving a page before sending it to IndexNow? Prevents sending it to often to IndexNow when multiple changes occur within a short time period. Default is 600 seconds = 10 minutes.');
    $f->inputType = 'number';
    $f->min = 1;
    $f->max = 10000;
    $f->value = $this -> gracePeriod ?: 10*60;
    $f->columnWidth = 25;
    $fs->add($f);

    /** @var InputfieldInteger $f */
    $f = $modules->get('InputfieldInteger');
    $f_name = 'daysToDeleteOldEntries';
    $f->name = $f_name;
    $f->label = $this->_('After how many days should old entries be deleted?');
    $f->detail = $this->_('The log table of IndexNow keeps track of all submitted pages. This table is cleaned up after a certain time. You can specify how many days old entries should be kept in the log.');
    $f->inputType = 'number';
    $f->min = 0;
    //$f->max = 10000;
    $f->value = $this -> daysToDeleteOldEntries ?: 10;
    $f->columnWidth = 25;
    $fs->add($f);
  }

  
  /**
  * Installation method
  */
  public function ___install() {
    $len = $this->wire()->database->getMaxIndexLength();
    
    try {
      $sql = '
        CREATE TABLE index_now (
          id int UNSIGNED NOT NULL AUTO_INCREMENT,
          pages_id int UNSIGNED NOT NULL,
          hostname TEXT,
          url TEXT,
          saved TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          response int UNSIGNED,
          submitted TIMESTAMP NULL DEFAULT null,
          PRIMARY KEY (id),
          INDEX url (url (:maxIndexLengthIndex)),
          INDEX submitted (submitted),
          UNIQUE unique_url(url(:maxIndexLengthUnique))
        )
      ';
      $stmt = $this->wire()->database->prepare($sql);
      $stmt->bindValue(':maxIndexLengthIndex', $len, \PDO::PARAM_INT);
      $stmt->bindValue(':maxIndexLengthUnique', $len, \PDO::PARAM_INT);
      $stmt->execute();
    } catch(\Exception $e) {
      $this->error($e->getMessage());
    }
  }

  /**
  * Uninstallation method
  */
  public function ___uninstall() {
    try {
      $this->wire()->database->exec('DROP TABLE index_now');
    } catch(\Exception $e) {
      $this->error($e->getMessage());
    }
  }
}
