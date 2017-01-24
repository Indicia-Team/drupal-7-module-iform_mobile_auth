<?php


function iform_mobile_auth_users_auth_post() {
  iform_mobile_auth_log('Users Auth POST');
  iform_mobile_auth_log(print_r($_POST, 1));

  if (!validate_user_auth_request()) {
    return;
  }

  $email = $_POST['email'];
  $password = $_POST['password'];
  $existing_user = user_load_by_mail($email);
  $existing_user_obj = entity_metadata_wrapper('user', $existing_user);

  require_once DRUPAL_ROOT . '/' . variable_get('password_inc', 'includes/password.inc');

  if (!user_check_password($password, $existing_user)) {
    error_print(401, 'Unauthorized', 'Incorrect password or email');
    return;
  }
  elseif ($existing_user->status != 1) {
    // Check for activation.
    error_print(401, 'Unauthorized', 'User not activated.');
    return;
  }
  // Create a new user-secret if this user doesn't already have one
  // (they registered directly with the site).
  check_existing_secret($existing_user_obj);

  // Check for existing user that do not have indicia id in their profile field.
  check_indicia_id($existing_user_obj);

  // Return the user's info to client.
  drupal_add_http_header('Status', '200 OK');
  return_user_details($existing_user_obj);
  iform_mobile_auth_log('User created');
}

function validate_user_auth_request() {
  // Reject submissions with an incorrect secret (or instances where secret is
  // not set).
  $provided_appsecret = $_POST['appsecret'];
  $provided_appname = empty($_POST['appname']) ? '' : $_POST['appname'];
  if (!iform_mobile_auth_authorise_app($provided_appname, $provided_appsecret)) {
    error_print(401, 'Unauthorized', 'Missing or incorrect shared app secret');

    return FALSE;
  }

  // Check email is valid.
  $email = $_POST['email'];
  if (empty($email)) {
    error_print(400, 'Bad Request', 'Invalid or missing email');

    return FALSE;
  }

  // Apply a password strength requirement.
  $password = $_POST['password'];
  if (empty($password)) {
    error_print(400, 'Bad Request', 'Invalid or missing password');

    return FALSE;
  }

  // Check for an existing user. If found (and password matches) return the
  // secret to all user to 'log in' via app.
  $existing_user = user_load_by_mail($email);
  if (!$existing_user) {
    error_print(401, 'Unauthorized', 'Incorrect password or email');

    return FALSE;
  }

  return TRUE;
}

function check_indicia_id($existing_user_obj) {
  $indicia_user_id = $existing_user_obj->{INDICIA_ID_FIELD}->value();
  if (empty($indicia_user_id) || $indicia_user_id == -1) {
    iform_mobile_auth_log('Associating indicia user id');
    // Look up indicia id.
    $indicia_user_id = iform_mobile_auth_get_user_id($existing_user_obj->mail->value(),
      $existing_user_obj->{FIRSTNAME_FIELD}->value(),
      $existing_user_obj->{SECONDNAME_FIELD}->value(),
      $existing_user_obj->uid->value());

    if (is_int($indicia_user_id)) {
      $existing_user_obj->{INDICIA_ID_FIELD}->set($indicia_user_id);
      $existing_user_obj->save();
    }
    else {
      $error = $indicia_user_id;
    }
  }
}

function check_existing_secret($existing_user_obj) {
  $secret = $existing_user_obj->{SHARED_SECRET_FIELD}->value();
  if (empty($secret)) {
    iform_mobile_auth_log('Creating new shared secret');
    $usersecret = iform_mobile_auth_generate_random_string(10);
    $existing_user_obj->{SHARED_SECRET_FIELD}->set($usersecret);
    $existing_user_obj->save();
  }
}
