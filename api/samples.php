<?php
/**
 * Created by PhpStorm.
 * User: karkaz
 * Date: 20/01/2017
 * Time: 11:03
 */


function iform_mobile_auth_samples_post() {
  iform_mobile_auth_log('Samples POST');
  iform_mobile_auth_log(print_r($_POST, 1));

  if (!validateRequest()) return;

  // Get auth
  try {
    $connection = iform_get_connection_details(NULL);
    $auth = data_entry_helper::get_read_write_auth($connection['website_id'], $connection['password']);
  } catch (Exception $e) {
    return error_print(502, 'Bad Gateway', 'Something went wrong in obtaining nonce');
  }

  // Construct photos
  process_files();

  // Construct post parameter array
  $submission = process_parameters($auth);

  // Check for duplicates
  if (has_duplicates($submission)) return;

  // Send record to indicia
  $response = data_entry_helper::forward_post_to('sample', $submission, $auth['write_tokens']);

  // Return response to client
  if (isset($response['error'])) {
    $errors = [];
    foreach ($response['errors'] as $key => $error) {
      $errors[$key] = [ title => $error ];
    }
    error_print(400, 'Bad Request', null, $errors);
  } else {
    // Created
    drupal_add_http_header('Status', '201 Created');
    $data = [
      type => "samples",
      id => $response['success']
    ];
    $output = [ data => $data ];
    drupal_json_output($output);
    iform_mobile_auth_log(print_r($response, 1));
  }
}

function process_files() {
  $processedFiles = array();
  foreach ($_FILES as $name => $info) {
    // if name is sample_photo1 or photo1 etc then process it.
    if (preg_match('/^(?P<sample>sample_)?photo(?P<id>[0-9])$/', $name, $matches)) {
      $baseModel = empty($matches['sample']) ? 'occurrence' : 'sample';
      $name = "$baseModel:image:$matches[id]";
      // Mobile generated files can have file name in format
      // resize.jpg?1333102276814 which will fail the warehouse submission
      // process.
      if (strstr($info['type'], 'jpg') !== FALSE || strstr($info['type'], 'jpeg') !== FALSE) {
        $info['name'] = uniqid() . '.jpg';
      }
      if (strstr($info['type'], 'png') !== FALSE) {
        $info['name'] = uniqid() . '.png';
      }
      $processedFiles[$name] = $info;
    }
    // Handle files sent along with a species checklist style submission. Files should be POSTed in
    // a field called sc:<gridrow>::photo[1-9] and will then get moved to the interim image folder and
    // linked to the form using a field called sc:<gridrow>::occurremce_media:path:[1-9]
    elseif (preg_match('/^sc:(?P<gridrow>.+)::photo(?P<id>[0-9])$/', $name, $matches)) {
      $interim_image_folder = isset(data_entry_helper::$interim_image_folder) ? data_entry_helper::$interim_image_folder : 'upload/';
      $uploadPath = data_entry_helper::relative_client_helper_path().$interim_image_folder;
      $interimFileName = uniqid().'.jpg';
      if (move_uploaded_file($info['tmp_name'], $uploadPath.$interimFileName)) {
        $_POST["sc:$matches[gridrow]::occurrence_medium:path:$matches[id]"] = $interimFileName;
      }
    }
  }
  if (!empty($processedFiles)) {
    $_FILES = $processedFiles;
    iform_mobile_auth_log(print_r($_FILES, 1));
  }
}

function process_parameters($auth) {
  $params = array();

  // General info.
  $safe_website_id = intval(isset($_POST['website_id']) ? $_POST['website_id'] : 0);
  $safe_survey_id = intval($_POST['survey_id']);
  $params['website_id'] = $safe_website_id;
  $params['survey_id'] = $safe_survey_id;
  $params['auth_token'] = $auth['write_tokens']['auth_token'];
  $params['nonce'] = $auth['write_tokens']['nonce'];;

  // Obtain coordinates of location if a name is specified.
  $georeftype = iform_mobile_auth_escape($_POST['sample:entered_sref_system']);

  $ref = trim(iform_mobile_auth_escape($_POST['sample:entered_sref']));

  unset($_POST['sample:entered_sref_system']);
  unset($_POST['sample:entered_sref']);

  // Enter sample info.
  $params['sample:entered_sref'] = $ref;
  $params['sample:entered_sref_system'] = $georeftype;
  $params['sample:geom'] = '';
  $params['gridmode'] = 'true';

  // Enter occurrence info.
  $params['occurrence:present'] = 'on';
  $params['occurrence:record_status'] = 'C';

  $is_occurrence_list = FALSE;
  // Add all supplied data.
  foreach ($_POST as $key => $value) {
    if (strstr($key, 'smpAttr:') != FALSE) {
      $params[$key] = iform_mobile_auth_escape($value);
    }
    elseif (strstr($key, 'occAttr:') != FALSE) {
      $params[$key] = iform_mobile_auth_escape($value);
    }
    elseif (strstr($key, 'sample:') != FALSE) {
      $params[$key] = iform_mobile_auth_escape($value);
    }
    elseif (strstr($key, 'occurrence:') != FALSE) {
      $params[$key] = iform_mobile_auth_escape($value);
    }
    elseif (strstr($key, 'sc:') != FALSE) {
      // sc: params indicate a list submission.
      $is_occurrence_list = TRUE;
      $params[$key] = iform_mobile_auth_escape($value);
    }
  }

  // We allow a sample with list of occurrences, sample with single occurrence or just a sample
  // to be submitted.
  if ($is_occurrence_list) {
    $attrArgs = array(
      'valuetable'=>'occurrence_attribute_value',
      'attrtable'=>'occurrence_attribute',
      'key'=>'occurrence_id',
      'fieldprefix'=>'occAttr',
      'extraParams'=>$auth['read'],
      'survey_id'=>$safe_survey_id
    );
    $occAttrs = data_entry_helper::getAttributes($attrArgs, false);
    $abundanceAttrs = array();
    foreach ($occAttrs as $attr) {
      if ($attr['system_function']==='sex_stage_count')
        $abundanceAttrs[] = $attr['attributeId'];
    }
    $submission = data_entry_helper::build_sample_occurrences_list_submission($params,false,$abundanceAttrs);
  }
  elseif (!empty($params['occurrence:taxa_taxon_list_id'])) {
    $submission = data_entry_helper::build_sample_occurrence_submission($params);
  }
  else {
    $submission = data_entry_helper::build_submission($params, array('model' => 'sample'));
  }

  iform_mobile_auth_log('SENDING');
  iform_mobile_auth_log(print_r($params, 1));

  return $submission;
}

function has_duplicates($submission) {
  $duplicates = find_duplicates($submission);
  if (count($duplicates) > 0) {
    $errors = [];
    foreach ($duplicates as $duplicate) {
      array_push($errors, [ title => 'Record already exists.']);
    }
    error_print(409, 'Conflict', null, $errors);
    return true;
  }
  return false;
}

function find_duplicates($submission) {
  $connection = iform_get_connection_details(NULL);
  $auth = data_entry_helper::get_read_auth($connection['website_id'], $connection['password']);

  $duplicates = [];
  foreach ($submission['subModels'] as $occurrence) {
    $existing = data_entry_helper::get_population_data(array(
      'table' => 'occurrence',
      'extraParams' => array_merge($auth, [
        'view' => 'detail',
        'external_key' => $occurrence['model']['fields']['external_key']['value']
      ]),
      'nocache' => true // forces a load from the db rather than local cache
    ));
    $duplicates = array_merge($duplicates, $existing);
  }

  return $duplicates;
}

function validateRequest() {
  if (!iform_mobile_auth_authorise_request()) {
    error_print(400, 'Bad Request', 'Could not find/authenticate user');
    return false;
  }

  $safe_website_id = intval(isset($_POST['website_id']) ? $_POST['website_id'] : 0);
  if ($safe_website_id == 0 || $safe_website_id != variable_get('indicia_website_id', '')) {
    error_print(400, 'Bad Request', 'Missing or incorrect website_id');
    return false;
  }
  $safe_survey_id = intval($_POST['survey_id']);
  if ($safe_survey_id == 0) {
    error_print(400, 'Bad Request', 'Missing or incorrect survey_id');
    return false;
  }
  return true;
}

function error_print($code, $status, $title, $errors = null) {
  // Something went wrong in obtaining nonce.
  drupal_add_http_header('Status', $code.' '.$status);
  if (is_null($errors)) {
    drupal_json_output([
      errors => [
        [ title => $title ]
      ]
    ]);
    iform_mobile_auth_log($title);
  } else {
    drupal_json_output([
      errors => $errors
    ]);
    iform_mobile_auth_log('Error');
    iform_mobile_auth_log(print_r($errors, 1));
  }
}