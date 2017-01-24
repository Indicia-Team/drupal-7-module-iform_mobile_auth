<?php
require 'samples.php';

define('API_BASE', 'api');
define('API_VERSION', 'v0.1');

/**
 * Implements hook_menu().
 */
function api_menu() {
  $items = array();
  $api_path = API_BASE . '/' . API_VERSION;

  // Mobile based record submission.
  $items["$api_path/samples"] = array(
    'title' => 'Samples POST',
    'page callback' => 'iform_mobile_auth_samples_post',
    'access callback' => TRUE,
  );

  return $items;
}
