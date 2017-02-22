<?php
require 'samples.php';
require 'users.php';
require 'users_auth.php';

const API_BASE = 'api';
const API_VERSION = 'v0.1';

/**
 * Implements hook_menu().
 */
function api_menu() {
  $items = array();
  $api_path = API_BASE . '/' . API_VERSION;

  $items["$api_path/samples"] = array(
    'title' => 'Samples POST',
    'page callback' => 'iform_mobile_auth_samples_post',
    'access callback' => TRUE,
  );

  $items["$api_path/users"] = array(
    'title' => 'Samples POST',
    'page callback' => 'iform_mobile_auth_users_post',
    'access callback' => TRUE,
  );

  $items["$api_path/users/auth"] = array(
    'title' => 'Samples POST',
    'page callback' => 'iform_mobile_auth_users_auth_post',
    'access callback' => TRUE,
  );

  return $items;
}
