<?php
/**
 * Created by PhpStorm.
 * User: karkaz
 * Date: 20/01/2017
 * Time: 11:03
 */


function iform_mobile_auth_samples() {
  iform_mobile_auth_log('iform_mobile_auth_client_submission');
  iform_mobile_auth_log('POST REQUEST');
  iform_mobile_auth_log(print_r($_POST, 1));

  // Steps 1 to 4 are covered in the request authorisation.
  if (!$existing_user = iform_mobile_auth_authorise_request()) {
    return;
  }
  // Wrap user for easier access to fields.
  $user_wrapped = entity_metadata_wrapper('user', $existing_user);

  $safe_website_id = intval(isset($_POST['website_id']) ? $_POST['website_id'] : 0);
  if ($safe_website_id == 0 ||
    $safe_website_id != variable_get('indicia_website_id', '')) {
    drupal_add_http_header('Status', '400 Bad Request');
    print 'Bad request';
    iform_mobile_auth_log('Missing or incorrect website_id');
    return;
  }
  $safe_survey_id = intval($_POST['survey_id']);
  if ($safe_survey_id == 0) {
    drupal_add_http_header('Status', '400 Bad Request');
    print 'Bad request';
    iform_mobile_auth_log('Missing or incorrect survey_id');
    return;
  }

  // Step 5.
  // Replace user parameters in submission.
  foreach ($_POST as $key => $value) {
    if ($value == '[userid]') {
      $_POST[$key] = $existing_user->uid;
    }
    if ($value == '[username]') {
      $_POST[$key] = $existing_user->name;
    }
    if ($value == '[email]') {
      $_POST[$key] = $existing_user->mail;
    }
    if ($value == '[firstname]') {
      $_POST[$key] = $user_wrapped->field_first_name->value();
    }
    if ($value == '[surname]') {
      $_POST[$key] = $user_wrapped->field_last_name->value();
    }
  }

  // Step 6.
  // Proceed to process the submission.

  // Get connection/indicia website details.
  $connection = iform_get_connection_details(NULL);

  $postargs = array();
  $postargs['website_id'] = $safe_website_id;

  // Obtain nonce.
  $curl_check = data_entry_helper::http_post(
    helper_config::$base_url . 'index.php/services/security/get_nonce',
    $postargs,
    FALSE);

  if (isset($curl_check['result'])) {
    // check the files for photos
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

    $nonce = $curl_check['output'];

    // Construct post parameter array.
    $params = array();

    // General info.
    $params['website_id'] = $safe_website_id;
    $params['survey_id'] = $safe_survey_id;
    $params['auth_token'] = sha1($nonce . ":" . $connection['password']);
    $params['nonce'] = $nonce;

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
      $auth = data_entry_helper::get_read_auth($connection['website_id'], $connection['password']);
      $attrArgs = array(
        'valuetable'=>'occurrence_attribute_value',
        'attrtable'=>'occurrence_attribute',
        'key'=>'occurrence_id',
        'fieldprefix'=>'occAttr',
        'extraParams'=>$auth,
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

    $write_tokens = array();
    $write_tokens['auth_token'] = sha1($nonce . ":" . $connection['password']);
    $write_tokens['nonce'] = $nonce;

    // Send record to indicia.
    $response = data_entry_helper::forward_post_to('sample', $submission, $write_tokens);

    $output = null;
    if (isset($response['error'])) {
      // Error
      drupal_add_http_header('Status', '400 Bad Request');
      $output = [
        tite => $response['error'],
        detail => $response['errors']
      ];
    } else {
      // Created
      drupal_add_http_header('Status', '201 Created');
      $output = [
        id => $response->success
      ];
    }
    drupal_json_output($output);
    iform_mobile_auth_log(print_r($response, 1));
  }
  else {
    // Something went wrong in obtaining nonce.
    drupal_add_http_header('Status', '502 Bad Gateway');
    print_r($curl_check);
    iform_mobile_auth_log($curl_check);
  }
}