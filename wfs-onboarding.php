<?php
/**
 * Plugin Name: White Fox Studios - Onboarding
 * Plugin URI: https://whitefoxstudios.net/
 * Description: Actions and methods for frontend form data to be stored throughout the app.
 * Version: 0.0.1
 * Author: White Fox Studios
 * Author URI: https://whitefoxstudios.net/
 * Text Domain: wfs-onboarding
 * Domain Path: /i18n/languages/
 * Requires at least: 6.1
 * Requires PHP: 7.3
 *
 * @package WFSOnboarding
 */

defined( 'ABSPATH' ) || exit;

add_action( 'elementor_pro/forms/new_record', 'wfs_onboarding', 10, 2 );

function wfs_onboarding( $record, $handler ){
  $form = $record->get_form_settings('form_name');
  $fields = wfs_onboarding_get_fields_assoc( $record->get( 'fields' ) );

  $actions = [
    'Proposal Agreement' => [
      'callback' => 'wfs_onboarding_actions_proposal_agreement',
      'args' => $fields,
    ],
    'Billing Contact' => [
      'callback' => 'wfs_onboarding_actions_billing_contact',
      'args' => $fields,
    ],
  ];

  if(function_exists($actions[$form]['callback'])){
    call_user_func($actions[$form]['callback'], $actions[$form]['callback']['args']);
  }
}

function wfs_onboarding_actions_billing_contact($fields) {
  $output = [
    'fields' => $fields,
  ];

  $email = $fields['billing_contact_email'];

  $name = wp_strip_all_tags( $fields['billing_contact_name'] );
  $names = wfs_onboarding_get_names( $name );

  // Check if the email exists as a user
  if ( wfs_onboarding_user_email_exists($email) ) {
    // If email exists, get the user
    $user = get_user_by( 'email', $email );
    $output['billing']['user'] = $user;

    // Get contact posts with the user's email
    $contact_post_ids = wfs_onboarding_get_posts_ids([
      'post_type' => 'contact',
      'meta_query' => [
        'key' => 'contact_email',
        'value' => $email,
      ],
    ]);

    // Add 'billing' term to the contact post
    if ($contact_post_ids !== false) {
      foreach ($contact_post_ids as $contact_post_id) {
        wp_set_post_terms($contact_post_id, 'billing', 'type', true);
      }
    }
  } else {
    // If email does not exist as a user, create a new user
    $user = wfs_onboarding_add_user( [
      'user_login'   => $email,
      'user_email'   => $email,
      'display_name' => $name
    ] + $names );

    $output['billing']['user'] = $user;

    // Create a new contact post
    $contact_post_args = [
      'post_title'    => $name,
      'post_author'   => $user->ID,
      'meta_input'    => [
        'contact_fname'   => $names['first_name'],
        'contact_lname'   => $names['last_name'],
        'contact_email'   => $email,
        'contact_phone'   => $fields['billing_contact_phone'],
      ],
    ];

    $contact_post = wfs_onboarding_insert_contact($contact_post_args);
    $output['billing']['contact'] = $contact_post;

    // Add 'billing' term to the contact post
    wp_set_post_terms($contact_post['id'], 'billing', 'type', true);
  }

  return $output;
}

function wfs_onboarding_actions_proposal_agreement($fields){
  $output = [
    'fields' => $fields,
  ];

  $email = $fields['proposal_contact_email'];

  $name  = wp_strip_all_tags( $fields['proposal_contact_name'] );
  $names = wfs_onboarding_get_names( $name );

  $user_id = $fields['user_id'];

  if ($user_id && is_numeric($user_id)) {
    $customer_user = get_userdata(intval($user_id));
  } elseif( ! wfs_onboarding_user_login_exists($email) || ! wfs_onboarding_user_email_exists($email) ){
    $customer_user = wfs_onboarding_add_user( [
      'user_login'   => $email,
      'user_email'   => $email,
      'display_name' => $name
    ] + $names );
  } // make sure customer_user is a valid wp User object

  $output['signee']['user'] = $customer_user;

  $company = $fields['business'];
  $total   = str_replace( ',', '', $fields['total'] );
  $author = $customer_user->ID;

  $contact_post_args = [
    'post_title'    => $name,
    'post_author'   => $author,
    'meta_input'    => [
      'contact_fname'   => $names['first_name'],
      'contact_lname'   => $names['last_name'],
      'contact_email'   => $email,
      'contact_company' => $company,
      'contact_domain'  => $fields['domain'],
      'contact_total'   => $total,
      'contact_deposit' => round( floatval( $total / 2), 2 ),
    ],
  ];

  $contact_post_ids = wfs_onboarding_get_posts_ids([
    'post_type' => 'contact',
    'meta_query' => [
      'key' => 'contact_email',
      'value' => $email,
    ],
  ]);

  if( $contact_post_ids !== false ){
    foreach($contact_post_ids as $key => $contact_post_id){
      $contact_posts_args[$key]['ID'] = $contact_post_id;
      $contact_posts_args[$key] += $contact_post_args;
      $contact_posts[$key] = wfs_onboarding_insert_contact($contact_posts_args[$key]);
    }
  } else {
    $contact_posts[] = wfs_onboarding_insert_contact($contact_post_args);
    $contact_post_ids = [$contact_posts[0]['id']];
  }

  $output['signee']['contacts'] = $contact_post_ids;

  $proposal_id = $fields['post_id'];

  $proposal = wfs_onboarding_get_post_by_id( $proposal_id );

  $output['proposal'] = $proposal;

  $client_post_args = [
    'post_title'   => $company,
    'post_author'  => $author,
    'meta_input'   => [
      'started' => date('Y-m-d'),
      'status'  => 'Signed',
    ],
  ];

  $client_post_ids = wfs_onboarding_get_posts_ids([
    'post_type' => 'client',
    'title' => $company,
  ]);

  if( $client_post_ids !== false ){
    foreach($client_post_ids as $key => $client_post_id){
      $client_posts_args[$key]['ID'] = $client_post_id;
      $client_posts_args[$key] += $client_post_args;
      $client_posts[$key] = wfs_onboarding_insert_client($client_posts_args[$key], $contact_post_ids);
    }
  } else {
    $client_posts[] = wfs_onboarding_insert_client($client_post_args, $contact_post_ids);
    $client_post_ids = [$client_posts[0]['id']];
  }

  $output['clients'] = $client_posts;

  return $output;
}

function wfs_onboarding_get_posts_ids($query){
  $ids = false;

  $posts = get_posts($query);

  if(!empty($posts)){
    foreach($posts as $post){
      $ids[] = $post->ID;
    }
  }

  return $ids;
}

function wfs_onboarding_insert_client($args, $contacts){
  $client = false;

  $defaults = [
    'post_type' => 'client',
    'post_status' => 'publish',
    'post_content'  => '',
  ];

  $args = wp_parse_args($args, $defaults);

  $new_client = apply_filters( 'wfs_onboarding_insert_client_args', $data );

  $id = wp_insert_post($new_client);

  if(!is_wp_error( $id )){
    if($contacts){
      foreach($contacts as $contact){
        add_row('contacts', $contact, $id);
      }
    }

    $client['id'] = $id;

    $post = get_post( $id );

    $client['data'] = $post;
    $client['args'] = $new_client;
  } else {
    $client['error'] = $id->get_error_message();
  }

  return $client;
}

function wfs_onboarding_insert_contact($args){
  $contact = false;

  $defaults = [
    'post_type' => 'contact',
    'post_status' => 'publish',
    'post_content'  => '',
  ];

  $args = wp_parse_args($args, $defaults);

  $new_contact = apply_filters( 'wfs_onboarding_insert_contact_args', $data );

  $id = wp_insert_post($new_contact);

  if(!is_wp_error( $id )){
    $contact_post_type = wp_set_post_terms($id, 'Proposal Signee', 'contact_types', true);

    $contact['id'] = $id;

    $post = get_post( $id );

    $contact['data'] = $post;
    $contact['args'] = $new_contact;
  } else {
    $contact['error'] = $id->get_error_message();
  }

  return $contact;
}

function wfs_onboarding_add_user( $data ){
  $user = false;

  $user_args = [
    'user_login' => '',
    'user_email' => '',
    'user_pass' => wp_generate_password( 20, false ),
    'first_name' => '',
    'last_name' => '',
    'display_name' => '',
    'role' => 'Customer',
  ];

  $data = wp_parse_args( $data, $user_args );

  $new_user = apply_filters( 'wfs_onboarding_add_user_args', $data );

  $user_id = wp_insert_user( $new_user );

  if(!is_wp_error($user_id)){
    wp_send_new_user_notifications($user_id);

    $user['id'] = $user_id;

    $user_data = get_userdata( $user_id );
    $user['data'] = $user_data;

    $new_user['password_reset_key'] = get_password_reset_key( $user_data );
    $user['args'] = $new_user;

    $user_data->add_role( $new_user['role'] );
  } else {
    $user['error'] = $user_id->get_error_message();
  }

  return $user;
}

function wfs_onboarding_get_names($name){
  preg_match('/\s(.+$)/', $name, $matches);
  $last = $matches[1];
  $first = trim(str_replace($last, '', $name));

  $names = [
    'first_name' => $first,
    'last_name' => $last,
    'display_name' => $name,
  ];

  return $names;
}

function wfs_onboarding_get_first_name($name){
  return wfs_onboarding_get_names($name)['first_name'];
}

function wfs_onboarding_get_post_by_id($id){
  $post = get_post($id);
  $fields = get_fields($id, false);

  return [
    'post' => $post,
    'fields' => $fields,
  ];
}

function wfs_onboarding_get_fields_assoc( $raw_fields ){
  $fields = false;

  foreach ( $raw_fields as $id => $field ) {
    $fields[ $id ] = $field['value'];
  }

  return $fields;
}

add_filter('wp_new_user_notification_email', 'wfs_onboarding_user_notification_email', 10, 3);

function wfs_onboarding_user_notification_email($wp_new_user_notification_email, $user, $blogname) {
  $options = wfs_onboarding_get_options();

  $name  = $options['from']['name'];
  $email = $options['from']['email'];

  $args = [
    'subject' => $options['subject'],
    'message' => wfs_onboarding_replace_message_tokens( $user->ID, $options['message'] ),
    'headers' => [
      'From' => "$name <$email>",
    ],
  ];

  $wp_new_user_notification_email['subject'] = $args['subject'];
  $wp_new_user_notification_email['message'] = $args['message'];
  $wp_new_user_notification_email['headers']['From'] = $args['headers']['From'];

  return $wp_new_user_notification_email;
}

add_action('admin_menu', 'wfs_onboarding_add_admin_menu');
add_action('admin_init', 'wfs_onboarding_settings_init');

function wfs_onboarding_add_admin_menu() {
  add_options_page('WFS Onboarding', 'WFS Onboarding', 'manage_options', 'wfs_onboarding', 'wfs_onboarding_options_page');
}

function wfs_onboarding_settings_init() {
  register_setting('wfs_onboarding', 'wfs_onboarding_options');

  add_settings_section(
    'wfs_onboarding_user_notification_email_settings_section',
    __('WFS Onboarding User Notification Email Settings', 'wfs-onboarding'),
    'wfs_onboarding_user_notification_email_settings_section_callback',
    'wfs_onboarding'
  );

  $options = wfs_onboarding_get_options();

  $fields = [
    [
      'name' => '[from][name]',
      'label_for' => __('From Name', 'wfs-onboarding'),
      'type' => 'text',
      'value' => $options['from']['name']
    ],
    [
      'name' => '[from][email]',
      'label_for' => __('From Email', 'wfs-onboarding'),
      'type' => 'text',
      'value' => $options['from']['email']
    ],
    [
      'name' => '[subject]',
      'label_for' => __('Subject', 'wfs-onboarding'),
      'type' => 'text',
      'value' => $options['subject']
    ],
    [
      'name' => '[message]',
      'label_for' => __('Message', 'wfs-onboarding'),
      'type' => 'textarea',
      'value' => $options['message']
    ],
  ];

  foreach ($fields as $field) {
    add_settings_field(
      $field['name'],
      $field['label_for'],
      'wfs_onboarding_render_options_field',
      'wfs_onboarding',
      'wfs_onboarding_user_notification_email_settings_section',
      $field
    );
  }
}

function wfs_onboarding_render_options_field($args) {
  $name = 'wfs_onboarding_options' . $args['name'];
  $type = $args['type'];
  $value = $args['value'];

  if ($type == 'text') {
    echo "<input type='text' name='$name' value='$value'>";
  } elseif ($type == 'textarea') {
    echo "<textarea cols='40' rows='5' name='$name'>$value</textarea>";
  }
}

function wfs_onboarding_user_notification_email_settings_section_callback() {
  echo __('Set your custom subject, message, and from header for the user activation email.', 'wfs-onboarding');
}

function wfs_onboarding_options_page() {
  ?>
  <form action='options.php' method='post'>
  <h2>WFS Onboarding</h2>
  <?php
  settings_fields('wfs_onboarding');
  do_settings_sections('wfs_onboarding');
  submit_button();
  ?>
  </form>
  <?php
}

function wfs_onboarding_replace_message_tokens($user_id, $message){
  $user = get_userdata($user_id);

  $key = get_password_reset_key( $user );

  $replacements = [
    'NAME' => $user->first_name,
    'KEY'  => $key,
    'LINK'  => network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ), 'login' ),
    'EMAIL' => $user->user_email,
  ];

  foreach( $replacements as $search => $replace ){
    $message = str_replace( '{{'.$search.'}}', $replace, $message );
  }

  return $message;
}

register_activation_hook(__FILE__, 'wfs_onboarding_set_default_options');

function wfs_onboarding_set_default_options() {
  $options = wfs_onboarding_get_options();

  if (false === wfs_onboarding_get_options()) {
    update_option('wfs_onboarding_options', $options);
  }
}

function wfs_onboarding_get_options(){
  $blogname = get_bloginfo( 'name' );

  $default_message = <<<MSG
  Hey {{NAME}},

  A new client portal account has been created for you on app.whitefoxstudios.net.

  To use your new client portal account you must first set your password by visting the following address: {{LINK}}.

  Your login username is your email {{EMAIL}};

  You will be able set your own password after activating.

  Once logged in you'll be able to view and manage your company and project information.

  Additionally you can view project progress, timeline and supply us with content, media and assets for your project.

  If you are a new web design client with us:
  Once your project is live your company dashboard will include performance metrics, support tickets, service plans + communication, deliverable reports archive and more.

  Otherwise: you'll have access to all our client portal features as soon as you activate your account.

  Thanks, talk soon…
  Michael Hurley - Director
  Marketing | White Fox Studios
  MSG;

  $defaults = [
    'from' => [
      'name' => $blogname,
      'email' => get_option('admin_email'),
    ],
    'subject' => 'Activate Your New Account On: ',
    'message' => $default_message,
  ];

  $options = get_option('wfs_onboarding_options', $defaults);

  foreach ($options as $key => $value) {
    if (empty($value)) {
      $options[$key] = $defaults[$key];
    }
  }

  return $options;
}

function wfs_onboarding_user_login_exists( $user_login ) {
    return ( username_exists( $user_login ) !== null );
}

function wfs_onboarding_user_email_exists( $user_email ) {
    return ( email_exists( $user_email ) !== false );
}

add_action('wp_enqueue_scripts', 'wfs_onboarding_enqueue_user_id_scripts');

function wfs_onboarding_enqueue_user_id_scripts() {
  wp_enqueue_script('wfs_onboarding_get_nonce', plugin_dir_url(__FILE__) . 'wfs-onboarding-user-id.js', [], '1.0', true);

  wp_localize_script('wfs_onboarding_get_nonce', 'wfs_onboarding_get_nonce_data', array(
    'ajax_url' => admin_url('admin-ajax.php'),
    'nonce'    => wp_create_nonce('wfs_onboarding_nonce'),
  ));
}

add_action( 'wp_ajax_wfs_onboarding_check_user_login', 'wfs_onboarding_check_user_login' );
add_action( 'wp_ajax_nopriv_wfs_onboarding_check_user_login', 'wfs_onboarding_check_user_login' );

function wfs_onboarding_check_user_login() {
    // Verify nonce
    check_ajax_referer( 'wfs_onboarding_nonce', 'nonce' );

    // Get the user login from the POST data
    $user_login = $_POST['user_login'];

    // Check if the user login exists and return the result
    $user_id = username_exists( $user_login );

    // Respond with the user ID if it exists, or 'false' if it doesn't
    if ( $user_id ) {
        wp_send_json_success( $user_id );
    } else {
        wp_send_json_error( false );
    }
}
