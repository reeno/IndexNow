<?php namespace ProcessWire;

$info = [
  'title' => 'IndexNow',
  'summary' => 'Submits changes of pages to IndexNow',
  'version' => '0.0.2',
  'author' => 'Reeno',
  'icon' => 'clock-o',
  'requires' => 'ProcessWire>=3.0.0, PHP>=7.0.0, LazyCron>=1.0.0',
  'installs' => array('ProcessIndexNow'),
  'href' => 'https://github.com/reeno/IndexNow',
  'autoload' => true
];