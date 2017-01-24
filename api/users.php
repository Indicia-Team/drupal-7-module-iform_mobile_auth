<?php

/**
 * Setup the profile fields.
 */
const SHARED_SECRET_FIELD = 'field_iform_auth_shared_secret';
const FIRSTNAME_FIELD = 'field_first_name';
const SECONDNAME_FIELD = 'field_last_name';
const CONFIRMATION_FIELD = 'field_confirmation_code';
const INDICIA_ID_FIELD = 'field_indicia_user_id';

function iform_mobile_auth_users_post() {
  iform_mobile_auth_log('Users POST');
  iform_mobile_auth_log(print_r($_POST, 1));

  if (!validate_user_post_request()) {
    return;
  }

  // Create account for user.
  try {
    $new_user_obj = create_new_user();
  }
  catch (Exception $e) {
    error_print(400, 'Bad Request', 'User could not be created.');
    return;
  }

  // Send activation mail.
  send_activation_email($new_user_obj);

  // Return the user's details to client.
  drupal_add_http_header('Status', '201 Created');
  return_user_details($new_user_obj);
  iform_mobile_auth_log('User created');
}

function validate_user_post_request() {
  // Reject submissions with an incorrect secret (or instances where secret is
  // not set).
  $provided_appsecret = $_POST['appsecret'];
  $provided_appname = empty($_POST['appname']) ? '' : $_POST['appname'];
  if (!iform_mobile_auth_authorise_app($provided_appname, $provided_appsecret)) {
    error_print(401, 'Unauthorized', 'Missing or incorrect shared app secret');

    return FALSE;
  }

  // Check minimum valid parameters.
  $firstname = $_POST['firstname'];
  $secondname = $_POST['secondname'];
  if (empty($firstname) || empty($secondname)) {
    error_print(400, 'Bad Request', 'Invalid or missing user firstname or secondname');

    return FALSE;
  }

  // Check email is valid.
  $email = $_POST['email'];
  if (empty($email) || valid_email_address($email) != 1) {
    error_print(400, 'Bad Request', 'Invalid or missing email');

    return FALSE;
  }

  // Apply a password strength requirement.
  $password = $_POST['password'];
  if (empty($password) || iform_mobile_auth_validate_password($password) != 1) {
    error_print(400, 'Bad Request', 'Invalid or missing password');

    return FALSE;
  }

  // Check for an existing user. If found return "already exists" error.
  $existing_user = user_load_by_mail($email);
  if ($existing_user) {
    error_print(400, 'Bad Request', 'Account already exists');

    return FALSE;
  }

  return TRUE;
}

function create_new_user() {
  // Pull out parameters from POST request.
  $firstname = empty($_POST['firstname']) ? '' : $_POST['firstname'];
  $secondname = empty($_POST['secondname']) ? '' : $_POST['secondname'];
  $email = $_POST['email'];
  $password = $_POST['password'];

  // Generate the user's shared secret returned to the app.
  $usersecret = iform_mobile_auth_generate_random_string(10);

  // Generate the user confirmation code returned via email.
  $confirmation_code = iform_mobile_auth_generate_random_string(20);

  // Look up indicia id. No need to send cms_id as this is a new user so they
  // cannot have any old records under this id to merge.
  $indicia_user_id = iform_mobile_auth_get_user_id($email, $firstname, $secondname);
  // Handle iform_mobile_auth_get_user_id returning an error.
  if (!is_int($indicia_user_id)) {
    // todo.
  }

  $user_details = array(
    'pass' => $password, /* handles the (unsalted) hash process */
    'name' => $email,
    'mail' => $email,
  );
  $user_details[FIRSTNAME_FIELD][LANGUAGE_NONE][0]['value'] = $firstname;
  $user_details[SECONDNAME_FIELD][LANGUAGE_NONE][0]['value'] = $secondname;
  $user_details[SHARED_SECRET_FIELD][LANGUAGE_NONE][0]['value'] = $usersecret;
  $user_details[CONFIRMATION_FIELD][LANGUAGE_NONE][0]['value'] = $confirmation_code;
  $user_details[INDICIA_ID_FIELD][LANGUAGE_NONE][0]['value'] = $indicia_user_id;

  $new_user = user_save(NULL, $user_details);
  $new_user_obj = entity_metadata_wrapper('user', $new_user);
  return $new_user_obj;
}

function send_activation_email($new_user) {
  $params = [
    'uid' => $new_user->getIdentifier(),
    'confirmation_code' => $new_user->{CONFIRMATION_FIELD}->value(),
  ];
  drupal_mail('iform_mobile_auth',
    'register',
    $new_user->mail->value(),
    user_preferred_language($new_user),
    $params
  );
}

function return_user_details($user_obj) {
  $data = [
    'type' => 'users',
    'id' => $user_obj->getIdentifier(),
    'email' => $user_obj->mail->value(),
    'usersecret' => $user_obj->{SHARED_SECRET_FIELD}->value(),
    'firstname' => $user_obj->{FIRSTNAME_FIELD}->value(),
    'secondname' => $user_obj->{SECONDNAME_FIELD}->value(),
  ];
  $output = ['data' => $data];
  drupal_json_output($output);
}
