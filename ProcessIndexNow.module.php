<?php namespace ProcessWire;
class ProcessIndexNow extends Process implements Module, ConfigurableModule {

  public function __construct() {
    $this->indexNowReturnCodes = [
      200 => 'OK',
      202 => 'Accepted',
      400 => 'Bad request',
      403 => 'Forbidden',
      422 => 'Unprocessable Entity',
      429 => 'Too Many Requests'
    ];
  }

  public function ___execute() {
    $ret = '';

    /** @var \ProcessWire\IndexNow $indexNow */
    $indexNow = $this->modules->get('IndexNow');
    $indexNowKey = $indexNow -> get('indexNowKey');
    $indexNow -> checkAndWriteFile($indexNowKey);

    $database = $this->wire()->database;

    /** @var \ProcessWire\MarkupAdminDataTable $table */
    $table = $this->modules->get('MarkupAdminDataTable');
    $table->setEncodeEntities(false);

    $table->headerRow(array(
        $this->_('Page ID'),
        $this->_('URI'),
        $this->_('Saved at'),
        $this->_('Response from IndexNow'),
        $this->_('Submitted at')
    ));

    $sql = '
      SELECT
        id,
        pages_id,
        url,
        saved,
        response,
        submitted
      FROM
        index_now
      WHERE
        1=1
      ORDER BY
        saved DESC
    ';

    $stmt = $database->query($sql);
    $tsSaved = null;
    $tsSubmitted = null;

    while ($row = $stmt->fetch()) {
      $tsSaved = strtotime($row['saved']);
      if(!is_null($tsSubmitted)) {
        $tsSubmitted = strtotime($row['submitted']);
      }

      $table->row([
        '<a href="/processwire/page/edit/?id='.$row['pages_id'].'">'.$row['pages_id'].'</a>',
        '<a href="'.$row['url'].'">'.$row['url'].'</a>',
        '<span title="'.$this->wire()->datetime->date('', $tsSaved).'">'.$this->wire()->datetime->relativeTimeStr($tsSaved).'</span>',
        is_null($row['response']) ? '&ndash;' : $row['response'].' '.$this -> indexNowReturnCodes[$row['response']], 
        is_null($tsSubmitted) ? '&ndash;' : '<span title="'.$this->wire()->datetime->date('', $tsSubmitted).'">'.$this->wire()->datetime->relativeTimeStr($tsSubmitted).'</span>'
      ]);
    }
    if(!is_null($tsSaved)) {
      $ret = $table->render();
    }
    else {
      $ret = '<p>'.$this->_('No URIs to show.').'</p>';
    }
    
    
    //$ret .= '<p>'.sprintf($this->_('For explanations of response codes see <a href="%s">the help pages of IndexNow</a>.'), 'https://www.indexnow.org/documentation#response').'<p>';
    $ret .= '<p><a href="https://www.bing.com/webmasters/indexnow?siteUrl='.urlencode($this->wire()->input->httpHostUrl()).'">'.sprintf($this->_('Status log at Bing.'), ).'</a><p>';


    return $ret;
  }

  public function getModuleConfigInputfields($inputfields) {
  }
}
