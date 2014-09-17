<?php

/**
 * @file
 * Iform Mobile Authentication Module's UI.
 */

/** 
 * Creates a blank form for a new app or populates it if editing.
 * @param $form
 * @param $form_state
 * @param null $apps
 * @return mixed
 */
function iform_mobile_auth_app($form, &$form_state, $apps = NULL) {
  if (!is_null($apps)) {
    //editing an existing app
    
    if (empty($apps)){
      //requested for an app with an id that doesn't exist in DB  
      drupal_not_found();
      return;
    } else {
      //since app account ids are unique and the URL argument is one id number
      //the $apps array returned from DB must contain information about only
      //one account
      $app = $apps[0];
      
      if(user_has_permission($app)){
        drupal_set_title(t('@title settings', array('@title' => $app['title'])));
      } else{
        return drupal_access_denied();
      }
    }
  }
  else {
    
    //new app, set variables to default values
    $app = array();
    $app['enabled'] = 1;
    $app['debug'] = 0;
    $app['name'] = '';
    $app['title'] = '';
    $app['description'] = '';
    $app['secret'] = iform_mobile_auth_generate_random_string(8);
    
    //extra fields
    $app['registration_subject'] = 'Welcome to the !site';
    $app['registration_body'] = 'Activate your account by clicking on the following link:<br/>!activation_url';
    $app['redirect_unsuccessful'] = '<front>';
    $app['registration_redirect'] = '<front>';
  } 
    
  //build form
  $form['enabled'] = array(
    '#type' => 'checkbox',
    '#title' => t('Enabled'),
    '#default_value' => $app['enabled'],
    '#description' => t('Check to enable app.')
  );
  $form['debug'] = array(
    '#type' => 'checkbox',
    '#title' => t('Debug mode'),
    '#default_value' => $app['debug'],
    '#description' => t('Check to enable debug mode. DO NOT ENABLE ON YOUR PRODUCTION SITE')
  );
  $form['title'] = array(
    '#type' => 'textfield',
    '#title' => t('Title'),
    '#default_value' => $app['title'],
    '#description' => t('Set the human readable title for this app.'),
    '#required' => TRUE,
  );
  $form['description'] = array(
    '#type' => 'textarea',
    '#title' => t('App description'),
    '#description' => t('Short app description.'),
    '#default_value' => $app['description'],
    '#required' => TRUE,
  );
  $form['name'] = array(
    '#type' => 'textfield',
    '#title' => t('App name'),
    '#default_value' => $app['name'],
    '#description' => t('Set the app name to be used in forms.
    \'anonymous\' allows to authorise without an appname.'),
    '#required' => TRUE,
  );
  $form['secret'] = array(
    '#type' => 'password',
    '#title' => t('Shared secret'),
    '#default_value' => '', 
    '#description' => t('Set the shared secret to be used in forms.'),
  );
  //extra
  $form['extra'] = array(
    '#type' => 'fieldset',
    '#title' => t('Extra parameters'),
    '#collapsible' => TRUE, 
    '#collapsed' => TRUE,
  );
  $form['extra']['registration_subject'] = array(
    '#type' => 'textfield',
    '#title' => t('Subject'),
    '#default_value' => $app['registration_subject'],
    '#description' => t('User registration email subject heading. Note: Only !site is supported.')
  );
  $form['extra']['registration_body'] = array(
    '#type' => 'textarea',
    '#title' => t('Body'),
    '#default_value' => $app['registration_body'],
    '#description' => t('User registration email body. Note: only !activation_url is supported. Emails are sent in plain text <i>not</i> HMTL!')
  );
  $form['extra']['registration_redirect'] = array(
    '#type' => 'textfield',
    '#title' => t('Redirection link'),
    '#default_value' => $app['registration_redirect'],
    '#description' => t('The url to redirect users to after clicking on the confirmation link. E.g. "&lt;front&gt;", "/node", "http://somewhere.com"')
  );
  $form['extra']['redirect_unsuccessful'] = array(
    '#type' => 'textfield',
    '#title' => t('Invalid redirection link'),
    '#default_value' => $app['redirect_unsuccessful'],
    '#description' => t('The url to redirect anonymous users to if attempting to reactive an already expired or invalid link E.g. "&lt;front&gt;", "/node", "http://somewhere.com"')
  );
  
  if (!empty($app['id'])) {
    //editing existing app
    $form['id'] = array(
      '#type' => 'value',
      '#value' => $app['id']
    );
    $form['changed'] = array(
      '#type' => 'value',
      '#value' => time()
    );
  } else {
    //new app
    $time = time();
    global $user;
    $form['created_by'] = array(
      '#type' => 'value',
      '#value' => $user->uid 
    );
    $form['created'] = array(
      '#type' => 'value',
      '#value' => $time
    );
    $form['changed'] = array(
      '#type' => 'value',
      '#value' => $time
    );
  }

  $form['submit'] = array(
    '#type' => 'submit',
    '#value' => t('Save')
  );

  $form['cancel'] = array(
    '#markup' => l(t('Cancel'), CONFIG_PATH . '/mobile/dashboard'),
  );
  
  //check if user has access to create new app
  if(user_access('user mobile auth') || user_access('admin mobile auth')){
   return $form;
  } else{
   return drupal_access_denied();
  }
}


/**
 * Main administrative page displaying the information about stored applications.
 * Allows to edit/delete each application's information and add new ones.
 * @return string
 */
function iform_mobile_auth_dashboard() {
  $r = '<p>Mobile applications accounts saved in Indicia Mobile Authorisation Module. 
    This allows you to manage unique accounts 
    as well as the ones sharing the same <i>appname</i> or <i>appsecret</i>.</p>';
    
  $r .= '<p>If used by older systems, there should be created an anonymous account with
    <i>appname</i> set to <i>anonymous</i> and an appropriate <i>appsecret</i>, allowing those older apps to login without an <i>appname</i></p>';
      
  $r .= '<fieldset><legend>Authentication accounts</legend>';
  
  //create table
  $header = array('Enabled', 'Title', 'Description', 'Debugging', '', '', '');
  $rows = array();
  $apps = iform_mobile_auth_app_load();
  foreach ($apps as $app){
    if(user_has_permission($app)){
      $row = array();
      $row[0] = '<input type="radio"' . ($app['enabled'] ? 'checked' : 'disabled') . ' >';
      $row[1] = $app['title'];
      $row[2] = $app['description'];
      $row[3] = '<input type="radio"' . ($app['debug'] ? 'checked' : 'disabled') . '>';
      $row[4] = l(t('Edit'), CONFIG_PATH . '/mobile/' . $app['id']);
      $row[5] = l(t('Delete'), CONFIG_PATH . '/mobile/delete/' . $app['id']);
      
      $rows[] = $row;
    }
  }
  
  $table = theme('table', array('header' => $header, 'rows' => $rows,
    'attributes' => array('style' => 'width:100%; text-align:left')));
  $r .= $table;
  
  // Add links beneath the table.
  $links = array(
    'link1' => array(
      'title' => t('Add Mobile Application'),
      'href' => CONFIG_PATH . '/mobile',
    ),
  );
  
  $r .= theme('links', array(
    'links' => $links,
    'attributes' => array('class' => array('links', 'inline'))));

  $r .= '</fieldset>';
  
  return $r;
}

/**
 * Submit handler to save an app.
 * Implements hook_submit() to submit a form produced by iform_mobile_auth_app().
 * @param $form
 * @param $form_state
 */
function iform_mobile_auth_app_submit($form, &$form_state) {
  //hashing secret
 if (!empty($form_state['values']['secret'])){
   $new_secret = $form_state['values']['secret'];
   $form_state['values']['secret'] = generate_hash($new_secret);
 } else {
   //don't overwrite old password if wasn't touched while editing
   unset($form_state['values']['secret']);
 }
    
  if (empty($form_state['values']['id'])) {
    // Save new app.
    drupal_write_record('iform_mobile_auth', $form_state['values']);
    $message = 'Created %app.';  
  }
  else {
    // Save existing app.
    drupal_write_record('iform_mobile_auth', $form_state['values'], 'id');
    $message = 'Saved %app.';  
  }

  //inform user and return to dashboard
    drupal_set_message(t($message, array('%app' => $form_state['values']['title'])));
    $form_state['redirect'] = CONFIG_PATH . '/mobile/dashboard';
}

/**
 * A confirmation page to check if the user is sure to delete an app from a database.
 * @param $form
 * @param $form_state
 * @param $apps
 * @internal param $a
 * @return mixed
 */
function iform_mobile_auth_delete($form, &$form_state, $apps) {

  //requested for an app account with an id that does not exist in DB
  if (empty($apps)){
    return drupal_not_found();
  }
  
  //since app account ids are unique and the URL argument is one id number
  //the $apps array returned from DB must contain information about only
  //one account
  $app = $apps[0];
  
  //set up the form information to be passed to submit handler
  $form = array();
  $form['id'] = array(
    '#type' => 'value',
    '#value' => $app['id'],
  );
   $form['title'] = array(
    '#type' => 'value',
    '#value' => $app['title'] ,
  );

  $message = t('Are you sure you want to delete %app ?', array('%app' => $app['title']));
  
  // check if the user has permission to delete an app
  if(user_has_permission($app)){
    return confirm_form($form, $message,
      CONFIG_PATH . '/mobile/dashboard', //redirect if cancelled
      t('This action cannot be undone.'),
      t('Delete'),
      t('Cancel')
    );
    //proceed to delete_submit handler
  }
  return drupal_access_denied();
}

/**
 * Submit handler to delete an app.
 * Implements hook_submit() to submit a confirmation form produced by iform_mobile_auth_delete().
 * @param $form
 * @param $form_state
 */
function iform_mobile_auth_delete_submit($form, &$form_state) {
  if ($form_state['values']['confirm']) {
    //delete app from database
    db_query('DELETE FROM {iform_mobile_auth} WHERE id = :id',
      array(':id' => $form_state['values']['id']));
    
    //inform user and return to dashboard
    $message = 'Deleted %app.';
    drupal_set_message(t($message, array('%app' => $form_state['values']['title'])));
  }
  $form_state['redirect'] = CONFIG_PATH . '/mobile/dashboard';
} 


