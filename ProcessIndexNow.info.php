<?php namespace ProcessWire;

$info = [
  'title' => 'IndexNow: Process module',
  'summary' => 'Adds a small table to see the status of the IndexNow submissions.',
  'version' => '0.1.1',
  'author' => 'Reeno',
  'icon' => 'clock-o',
  'requires' => 'ProcessWire>=3.0.0, PHP>=7.0.0, IndexNow',
  'href' => 'https://github.com/reeno/IndexNow',
  'page' => array(
  'name' => 'index-now',
  'title' => 'IndexNow',
  'parent' => 'setup',
  ),
  'permission' => 'index-now',
  'permissions' => array(
  'index-now' => 'Use the IndexNow module'
  ),
];