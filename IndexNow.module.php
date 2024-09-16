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
      'every30Seconds' => $this->_('every 30 seconds'),
      'everyMinute'    => $this->_('every minute'),	
      'every2Minutes'  => $this->_('every 2 minutes'),
      'every3Minutes'  => $this->_('every 3 minutes'),
      'every4Minutes'  => $this->_('every 4 minutes'),
      'every5Minutes'  => $this->_('every 5 minutes'),
      'every10Minutes' => $this->_('every 10 minutes'),
      'every15Minutes' => $this->_('every 15 minutes'),
      'every30Minutes' => $this->_('every 30 minutes'),
      'every45Minutes' => $this->_('every 45 minutes'),
      'everyHour'      => $this->_('every hour'),
      'every2Hours'    => $this->_('every 2 hours'),
      'every4Hours'    => $this->_('every 4 hours'),
      'every6Hours'    => $this->_('every 6 hours'),
      'every12Hours'   => $this->_('every 12 hours'),
      'everyDay'       => $this->_('every day'),
      'every2Days'     => $this->_('every 2 days'),
      'every4Days'     => $this->_('every 4 days'),
      'everyWeek'      => $this->_('every week'),
      'every2Weeks'    => $this->_('every 2 weeks'),
      'every4Weeks'    => $this->_('every 4 weeks')
    ];
  }
  
  /**
   * Ready method. Sets hooks.
   */
  public function ready() {
    // page changes hooks
    $this->pages->addHookAfter('Pages::save',   $this, 'processPageUpdate');
    $this->pages->addHookAfter('Pages::add',    $this, 'processPageUpdate');
    $this->pages->addHookAfter('Pages::new',    $this, 'processPageUpdate');
    
    // before as the page is not public anymore after trashing
    $this->pages->addHookBefore('Pages::trash', $this, 'processPageUpdate');

    // cron hook
    if($this->indexNowTimefunc) {
      $this->addHookAfter('LazyCron::'.$this -> indexNowTimefunc, $this, 'processCron');
    }
  }

  /*
   * Process the cron.
   * 
   * Sends new URIs to IndexNow.
   * Writes log entries if any errors occur.
   * Clears the log after a certain time.   
   */
  public function processCron() {
    // only fetch what wasn't send already (response IS NULL) or sent with error (response > 202)
    $sql = '
      SELECT
        id,
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

    $stmt->bindValue(':limit',       $this->get('urisPerCron'), \PDO::PARAM_INT); 
    $stmt->bindValue(':gracePeriod', $this->get('gracePeriod'), \PDO::PARAM_INT);

    $stmt->execute();

    $hostname= null;
    $ids = [];
    $json = [
      'host'    => null,
      'key'     => $this->get('indexNowKey'),
      'urlList' => []
    ];

    while ($row = $stmt->fetch()) {
      // hostname not set yet
      if($hostname === null) {
        $hostname = parse_url($row['url'], PHP_URL_HOST);
        $json['host'] = $hostname;
      /* hostname already set
       * check if the new row has another hostname. Should never happen, but just in case... */
      } elseif($hostname !== parse_url($row['url'], PHP_URL_HOST)) {
        // if hostnames are different, skip this row and run it in the next cron execution
        continue;
      }

      $json['urlList'][] = $row['url'];
      $ids[] = $row['id'];
    }

    $this->wire()->log('IndexNow: =================================================================');

    // check if key file exists and is accessible
    if(!$fc = file_get_contents('http://'.$hostname.'/'.$json['key'].'.txt')) {
      $this->wire()->log(sprintf($this->_('[IndexNow Cron %s] IndexNow key file does not exist.'), $hostname));
      return;
    }

    // check if key file content matches specification
    if($fc !== $json['key']) {
      $this->wire()->log(sprintf($this->_('[IndexNow Cron %s] IndexNow key file content does not match specification.'), $hostname));
      return;
    }

    $this->wire()->log('IndexNow: json '.json_encode($json));

    try {
      $ch = curl_init();

      curl_setopt($ch, CURLOPT_URL, 'https://api.indexnow.org/indexnow');
      curl_setopt($ch, CURLOPT_POST, 1);
      curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Content-Type: application/json; charset=utf-8'
      ));
      curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      curl_setopt($ch, CURLOPT_HEADER, 1);

      $response = curl_exec($ch);

      $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
      curl_close($ch);
    }
    catch(\Exception $e) {
      $this->wire()->log(sprintf($this->_('[IndexNow Cron %s] Curl-Error: %s'), $hostname, $e->getMessage()));
      return;
    }


    // Binding IN is tricky... See https://phpdelusions.net/pdo#in
    $params = [':response' => $http_status];
    $in = '';
    $i = 0;
    $in_params = [];

    foreach ($ids as $item) {
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
          submitted = CURRENT_TIMESTAMP,
          response = :response
        WHERE
          id IN ('.$in.')
      ';

      $this->wire()->log('IndexNow: sql: '.$sql);

      $query = $this->wire()->database->prepare($sql);
      $query->execute(array_merge($params, $in_params));

    } catch(\Exception $e) {
      $this->wire()->log(sprintf($this->_('[IndexNow Cron %s] Database-Error: %s'), $hostname, $e->getMessage()));
      return;
    }
  
    // cleanup old successful entries
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
      $this->wire()->log(sprintf($this->_('[IndexNow Cron %s] Database-Cleanup-Error: %s'), $hostname, $e->getMessage()));
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
      OR  $page->rootParent()->id === 2 // admin page
      OR  $page->isHidden() // hidden page
      OR  $page->isUnpublished() // unpublished page
      OR !$page->isPublic() // public and viewable by all
    ) {
      return;
    }

    // fetch all uris of the current page
    foreach($page -> urls([
      'http'      => true,
      'past'      => false,
      'languages' => true
    ]) as $uri) { 
      try {
        // Upsert
        $sql = '
          INSERT INTO index_now (
            pages_id,
            url,
            saved
          )
          VALUES(
            :pageid,
            :url,
            CURRENT_TIMESTAMP
          )
          ON DUPLICATE KEY UPDATE
            saved = CURRENT_TIMESTAMP,
            response = null,
            submitted = null
        ';

        $query = $this->wire()->database->prepare($sql);

        $query->bindValue(':pageid',    $page->id, \PDO::PARAM_INT);
        $query->bindValue(':url',       $uri, \PDO::PARAM_STR);

        $query->execute();
      } catch(\Exception $e) {
        $this->error($e->getMessage());
      }
    }
  }

  /**
   * Generate a new IndexNow key
   * Source: https://stackoverflow.com/a/15875555
   * - Method is not used right now. -
   * 
   * @return string The new key
   */
  private function generateIndexNowKey() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    $uuid = vsprintf('%s%s%s%s%s%s%s%s', str_split(bin2hex($data), 4));

    return $uuid;
  }

  /**
   * Check if the IndexNow key file exists and is valid. If it doesn't exist, try to create it.
   * 
   * @param string $indexNowKey The IndexNow key
   * 
   * @return bool True if the file exists and is valid, false otherwise
   */
  public function checkAndWriteFile() {
    $indexNowKey = $this->get('indexNowKey');

    // Check if the key is defined
    if(empty($indexNowKey)) {
      $this->error($this->_('Your IndexNow key is not defined. Please set it in the module settings.'));
      return FALSE;
    }

    // define file path
    $indexNowKeyFile = $this->wire::getRootPath().$indexNowKey.'.txt';

    // file does not exist yet
    if(!file_exists($indexNowKeyFile)) {

      // check if the file is writeable
      if(!is_writable($indexNowKeyFile)) {
        $this->error(sprintf($this->_('The path of your IndexNow key file can\'t be written. Please create it manually. Path: %s'), $indexNowKeyFile));
        return FALSE;
      }
  
      // try to open for writing
      if(!$fp = fopen($indexNowKeyFile, 'w')) {
        $this->error(sprintf($this->_('Your IndexNow key file can\'t be opened to write. Please create it manually. Path: %s'), $indexNowKeyFile));
        return FALSE;
      }
        
      // write the contents
      if (fwrite($fp, $indexNowKey) === FALSE) {
        $this->error(sprintf($this->_('Your IndexNow key file can\'t be written. Please create it manually. Path: %s'), $indexNowKeyFile));
        return FALSE;
      }
    
      fclose($fp);

      // file was writeable and the content could be written
      return TRUE;
    }

    // try to open it for reading
    if(!$fp = fopen($indexNowKeyFile, 'r')) {
      $this->error(sprintf($this->_('Your IndexNow key file can\'t be opened. Check if it exists. Path: $%'), $indexNowKeyFile));
      return FALSE;
    }

    $fs = filesize($indexNowKeyFile);
    if($fs === 0) {
      $this->error(sprintf($this->_('Your IndexNow key file exists but is empty. Please check the content. Path: %s'), $indexNowKeyFile));
      return FALSE;
    }
    // read the content
    $contents = fread($fp, $fs);

    // check if the content is the same as the key
    if($contents !== $indexNowKey) {
      $this->error(sprintf($this->_('Your IndexNow key file exists but has the wrong content. Please check the content. Path: %s'), $indexNowKeyFile));
      return FALSE;
    }

    // check if access via HTTP is possible
    if(!file_get_contents($this->wire()->input->httpHostUrl().'/'.$indexNowKey.'.txt')) {
      $this->error(sprintf($this->_('Your IndexNow key file can\'t be accessed via HTTP. Please check the permissions. Path: %s'), $this->wire()->input->httpHostUrl().'/'.$indexNowKey.'.txt'));
    }

    // all went well
    return TRUE;
  }
  
  /**
   * Get the module config inputfields
   */
  public function getModuleConfigInputfields($inputfields) {
    $modules = $this->wire()->modules;

    // only via GET so it doesn't get called twice when saving the module config
    if($_SERVER['REQUEST_METHOD'] === 'GET') {
      $this->checkAndWriteFile();
    }

    /** @var InputfieldFieldset $fs */
    $fs = $modules->get('InputfieldFieldset');
    $fs->label = $this->_('IndexNow settings');

    $inputfields->add($fs);

    /** @var InputfieldText $f */
    $f = $modules->get('InputfieldText');
    $f_name = 'indexNowKey';
    $f->name = $f_name;
    $f->label = $this->_('IndexNow API key');
    $f->detail = $this->_('Get an IndexNow API key and enter it here.');
    // https://www.bing.com/indexnow/getstarted#implementation
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
    $f->label = $this->_('URIs per cron execution');
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
    $f->label = $this->_('Log cleaning period');
    $f->detail = $this->_('The log table of IndexNow keeps track of all submitted pages. This table is cleaned up after a certain time. You can specify how many days old entries should be kept in the log.');
    $f->inputType = 'number';
    $f->min = 0;
    $f->value = $this -> daysToDeleteOldEntries ?: 10;
    $f->columnWidth = 25;
    $fs->add($f);
  }

  
  /**
  * Installation method. Creates the necessary database table.
  */
  public function ___install() {
    $len = $this->wire()->database->getMaxIndexLength();
    
    try {
      $sql = '
        CREATE TABLE index_now (
          id int UNSIGNED NOT NULL AUTO_INCREMENT,
          pages_id int UNSIGNED NOT NULL,
          url TEXT,
          saved TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          response int UNSIGNED,
          submitted TIMESTAMP NULL DEFAULT null,
          PRIMARY KEY (id),
          INDEX url (url (:maxIndexLengthIndex)),
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
  * Uninstallation method. Drops the created database table.
  */
  public function ___uninstall() {
    try {
      $this->wire()->database->exec('DROP TABLE index_now');
    } catch(\Exception $e) {
      $this->error($e->getMessage());
    }
  }
}
