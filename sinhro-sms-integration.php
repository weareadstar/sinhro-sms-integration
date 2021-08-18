<?php
/*
Plugin Name:  Sinhro Integration
Plugin URI:   https://github.com/mpandzo/sinhro-sms-integration
Description:  A WordPress plugin that allows integration with the http://gw.sinhro.si/api/http api for sending SMSs
Version:      1.0.3
Author:       adstar
Author URI:   https://adstar-agency.com
License:      MIT License
*/

namespace Adstar\SinhroIntegration;

defined("ABSPATH") || exit; // Exit if accessed directly

# Include the Autoloader (see "Libraries" for install instructions)
require __DIR__ . "/mandrill/vendor/autoload.php";

if (!defined("SINHRO_INTEGRATION_VERSION")) {
    define("SINHRO_INTEGRATION_VERSION", "1.0.3");
}

if (!defined("SINHRO_INTEGRATION_CART_TABLE_NAME")) {
  define("SINHRO_INTEGRATION_CART_TABLE_NAME", "ssi_temp_cart");
}

if (!defined("POST_PURCHASE_ENTRIES_TABLE_NAME")) {
  define("POST_PURCHASE_ENTRIES_TABLE_NAME", "ssi_post_purchase_entries");
}

if (!defined("POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME")) {
  define("POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME", "ssi_post_purchase_survey");
}

class SinhroIntegration
{
    private $plugin_name = "SinhroIntegration";
    private $plugin_log_file = "";

    public function __construct()
    {
        $this->plugin_log_file = plugin_dir_path(__FILE__) . "ssi-debug.log";

        // Check if WooCommerce is active
        require_once(ABSPATH . "/wp-admin/includes/plugin.php");
        if (!is_plugin_active("woocommerce/woocommerce.php") && !function_exists("WC")) {
            return false;
        }

        $this->hooks();
    }

    public function hooks()
    {
        // activation/deactivation
        register_activation_hook(__FILE__, array($this, "plugin_activate"));
        register_deactivation_hook(__FILE__, array($this, "plugin_deactivate"));

        // frontend hooks
        add_action("init", array($this, "load_plugin_textdomain"));
        add_action("wp_enqueue_scripts", array($this, "wp_enqueue_scripts"));

        // admin hooks
        add_action("admin_menu", array($this, "admin_menu"), 10);
        add_action("admin_init", array($this, "register_sinhro_sms_integration_settings"));
        add_action("admin_init", array($this, "send_test_sms"));
        add_action("admin_init", array($this, "send_test_email"));

        // woocommerce related hooks
        // order status changed (for post purchase after completed)
        add_action("woocommerce_order_status_changed", array($this, "woocommerce_order_status_changed"), 10, 3);

        // create unique cart id for cart
        add_action("woocommerce_init", array($this, "woocommerce_init"), 10);

        // order is processed so remove any temporary references
        add_action("woocommerce_checkout_order_processed", array($this, "woocommerce_order_processed"), 10);

        // add cart unique hidden field to checkout form
        add_action("woocommerce_review_order_after_submit", array($this, "woocommerce_review_order_after_submit"));

        // ajax hooks
        add_action("wp_ajax_save_checkout_info", array($this, "save_checkout_info"));
        add_action("wp_ajax_nopriv_save_checkout_info", array($this, "save_checkout_info"));

        // cron job code
        add_action("admin_init", array($this, "register_cart_cron_job"));
        add_action("ssi_cart_process_sms", array($this, "process_abandoned_carts"));
        add_action("ssi_post_purchase_surveys", array($this, "process_post_purchase_surveys"));
        add_filter("cron_schedules", array($this, "add_cron_interval"));

        // post purchase survey form shortcode
        add_shortcode('post_purchase_survey', array($this, 'post_purchase_survey_shortcode_function'));
        add_shortcode('post_purchase_survey_results', array($this, 'post_purchase_survey_results_shortcode_function'));
    }

    function get_current_page_url() {
        global $wp;
        return add_query_arg( $_SERVER['QUERY_STRING'], '', home_url( $wp->request ) );
    }

    public function post_purchase_survey_results_shortcode_function($atts = array()) {
      global $wpdb;

      $output = "";

      $atts = shortcode_atts( array(
        'productid' => 0,
      ), $atts );

      if (isset($atts["productid"])) {
          $product_id = intval($atts["productid"]);
          if ($product_id > 0) {
            $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME;
            $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$temp_cart_table_name} WHERE product_ids LIKE '%%%s%'", md5($product_id)));
            $count_results = count($results);
            $score_sum = 0;
            if ($count_results) {
              foreach ($results as $result) {
                $score_sum += $result->overall_rating;
              }

              $output = sprintf(__("This product has an average rating of %.2f based on a survey of %d people", "sinhro-sms-integration"), $score_sum/$count_results, $count_results);
            }
          }
      }

      return $output;
    }

    public function post_purchase_survey_shortcode_function($atts = array()) {
        global $wpdb;

        $output = "";

        $row_post_purchase_survey_results = false;
        $row_post_purchase_entries = false;

        if (isset($_GET["ppshash"])) {
            $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME;
            $row_post_purchase_survey_results = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$temp_cart_table_name} WHERE unique_hash=%s", $_GET["ppshash"]));

            $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;
            $row_post_purchase_entries = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$temp_cart_table_name} WHERE unique_hash=%s", $_GET["ppshash"]));
        }

        if (isset($_GET["ppshash"]) && !$row_post_purchase_survey_results && !$row_post_purchase_entries->survey_completed) {

          ob_start();

          if (isset($_POST['ssi_post_purchase_survey_nonce']) && wp_verify_nonce($_POST['ssi_post_purchase_survey_nonce'], 'ssi_post_purchase_survey')) {
              $unique_hash = $_GET["ppshash"];

              $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;
              $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET survey_completed=1 WHERE unique_hash=%s", $unique_hash));

              $question_1_answer = 0;
              if (isset($_POST["survey_question_1"])) {
                $question_1_answer = intval($_POST["survey_question_1"]);
              }

              $question_2_answer = 0;
              if (isset($_POST["survey_question_2"])) {
                $question_2_answer = intval($_POST["survey_question_2"]);
              }

              $question_3_answer = 0;
              if (isset($_POST["survey_question_3"])) {
                $question_3_answer = intval($_POST["survey_question_3"]);
              }

              $question_4_answer = 0;
              if (isset($_POST["survey_question_4"])) {
                $question_4_answer = intval($_POST["survey_question_4"]);
              }

              $question_5_answer = 0;
              if (isset($_POST["survey_question_5"])) {
                $question_5_answer = intval($_POST["survey_question_5"]);
              }

              $overall_rating = ($question_1_answer + $question_2_answer + $question_3_answer + $question_4_answer + $question_5_answer) / 5;

              $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME;
              $wpdb->query($wpdb->prepare("INSERT INTO $temp_cart_table_name (unique_hash, product_ids, order_id, question_1_answer, question_2_answer, question_3_answer, question_4_answer, question_5_answer, overall_rating) VALUES (%s, %s, %s, %d, %d, %d, %d, %d, %f)", $unique_hash, $row_post_purchase_entries->product_ids, $row_post_purchase_entries->order_id, $question_1_answer, $question_2_answer, $question_3_answer, $question_4_answer, $question_5_answer, $overall_rating));

              ?>
              <p>
                <?php _e("Thank you for submitting!", "sinhro-sms-integration"); ?>
              </p>
              <?php
          } else {
              $current_url = $this->get_current_page_url();

              $post_purchase_survey_question_1 = get_option("ssi_post_purchase_survey_question_1");
              $post_purchase_survey_question_2 = get_option("ssi_post_purchase_survey_question_2");
              $post_purchase_survey_question_3 = get_option("ssi_post_purchase_survey_question_3");
              $post_purchase_survey_question_4 = get_option("ssi_post_purchase_survey_question_4");
              $post_purchase_survey_question_5 = get_option("ssi_post_purchase_survey_question_5");
          ?>
          <form method="POST" action="<?php echo esc_url($current_url); ?>">
              <p>
                <?php _e("Please answer the following questions with regards your purchasing experience:", "sinhro-sms-integration"); ?>
              </p>
              <div>
                <p><?php echo $post_purchase_survey_question_1; ?></p>
                <div>
                  <label for="survey_question_1_1">1</label>
                  <input type="radio" name="survey_question_1" id="survey_question_1_1" value="1" />
                  <label for="survey_question_1_2">2</label>
                  <input type="radio" name="survey_question_1" id="survey_question_1_2" value="2" />
                  <label for="survey_question_1_3">3</label>
                  <input type="radio" name="survey_question_1" id="survey_question_1_3" value="3" />
                  <label for="survey_question_1_4">4</label>
                  <input type="radio" name="survey_question_1" id="survey_question_1_4" value="4" />
                  <label for="survey_question_1_5">5</label>
                  <input type="radio" name="survey_question_1" id="survey_question_1_5" value="5" />
                </div>
              </div>
              <div>
                <p><?php echo $post_purchase_survey_question_2; ?></p>
                <div>
                  <label for="survey_question_2_1">1</label>
                  <input type="radio" name="survey_question_2" id="survey_question_2_1" value="1" />
                  <label for="survey_question_2_2">2</label>
                  <input type="radio" name="survey_question_2" id="survey_question_2_2" value="2" />
                  <label for="survey_question_2_3">3</label>
                  <input type="radio" name="survey_question_2" id="survey_question_2_3" value="3" />
                  <label for="survey_question_2_4">4</label>
                  <input type="radio" name="survey_question_2" id="survey_question_2_4" value="4" />
                  <label for="survey_question_2_5">5</label>
                  <input type="radio" name="survey_question_2" id="survey_question_2_5" value="5" />
                </div>
              </div>
              <div>
                <p><?php echo $post_purchase_survey_question_3; ?></p>
                <div>
                  <label for="survey_question_3_1">1</label>
                  <input type="radio" name="survey_question_3" id="survey_question_3_1" value="1" />
                  <label for="survey_question_3_2">2</label>
                  <input type="radio" name="survey_question_3" id="survey_question_3_2" value="2" />
                  <label for="survey_question_3_3">3</label>
                  <input type="radio" name="survey_question_3" id="survey_question_3_3" value="3" />
                  <label for="survey_question_3_4">4</label>
                  <input type="radio" name="survey_question_3" id="survey_question_3_4" value="4" />
                  <label for="survey_question_3_5">5</label>
                  <input type="radio" name="survey_question_3" id="survey_question_3_5" value="5" />
                </div>
              </div>
              <div>
                <p><?php echo $post_purchase_survey_question_4; ?></p>
                <div>
                  <label for="survey_question_4_1">1</label>
                  <input type="radio" name="survey_question_4" id="survey_question_4_1" value="1" />
                  <label for="survey_question_4_2">2</label>
                  <input type="radio" name="survey_question_4" id="survey_question_4_2" value="2" />
                  <label for="survey_question_4_3">3</label>
                  <input type="radio" name="survey_question_4" id="survey_question_4_3" value="3" />
                  <label for="survey_question_4_4">4</label>
                  <input type="radio" name="survey_question_4" id="survey_question_4_4" value="4" />
                  <label for="survey_question_4_5">5</label>
                  <input type="radio" name="survey_question_4" id="survey_question_4_5" value="5" />
                </div>
              </div>
              <div>
                <p><?php echo $post_purchase_survey_question_5; ?></p>
                <div>
                  <label for="survey_question_5_1">1</label>
                  <input type="radio" name="survey_question_5" id="survey_question_5_1" value="1" />
                  <label for="survey_question_5_2">2</label>
                  <input type="radio" name="survey_question_5" id="survey_question_5_2" value="2" />
                  <label for="survey_question_5_3">3</label>
                  <input type="radio" name="survey_question_5" id="survey_question_5_3" value="3" />
                  <label for="survey_question_5_4">4</label>
                  <input type="radio" name="survey_question_5" id="survey_question_5_4" value="4" />
                  <label for="survey_question_5_5">5</label>
                  <input type="radio" name="survey_question_5" id="survey_question_5_5" value="5" />
                </div>
              </div>
              <p>
                <?php wp_nonce_field('ssi_post_purchase_survey', 'ssi_post_purchase_survey_nonce'); ?>
                <input type="submit" value="<?php _e("Submit", "sinhro-sms-integration"); ?>">
              </p>
          </form>
          <?php
          }

          $output = ob_get_clean();
        }

        return $output;
    }

    public function add_cron_interval($schedules)
    {
        $schedules["five_minutes"] = array(
            "interval" => 5 * 60,
            "display"  => esc_html__("Every Five Minutes", "sinhro-sms-integration")
        );

        $schedules["seven_minutes"] = array(
          "interval" => 7 * 60,
          "display"  => esc_html__("Every Seven Minutes", "sinhro-sms-integration")
        );

        return $schedules;
    }

    public function get_post_purchase_email_1_entries($interval_minutes) {
        global $wpdb;

        $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;

        $results = $wpdb->get_results($wpdb->prepare("
          SELECT * FROM $temp_cart_table_name
          WHERE email_1_sent = 0 AND email_address != '' AND survey_completed = 0 AND created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));

        return $results;
    }

    public function get_post_purchase_sms_1_entries($interval_minutes) {
        global $wpdb;

        $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;

        $results = $wpdb->get_results($wpdb->prepare("
          SELECT * FROM $temp_cart_table_name
          WHERE email_1_sent = 1 AND sms_1_sent = 0 AND phone != '' AND survey_completed = 0 AND sms_send_errors < 3 AND created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));

        return $results;
    }

    public function get_email_step_1_cart_entries($interval_minutes) {
      return $this->get_step_x_cart_entries(sprintf("(email_1_sent=0 AND email_address!='') AND email_2_sent=0 AND email_3_sent=0 AND
        created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));
    }

    public function get_email_step_2_cart_entries($interval_minutes) {
      return $this->get_step_x_cart_entries(sprintf("email_1_sent=1 AND (email_2_sent=0 AND email_address!='') AND email_3_sent=0 AND
        created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));
    }

    public function get_email_step_3_cart_entries($interval_minutes) {
      return $this->get_step_x_cart_entries(sprintf("email_1_sent=1 AND email_2_sent=1 AND (email_3_sent=0 AND email_address != '') AND
        created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));
    }

    public function get_sms_step_1_cart_entries($interval_minutes) {
      return $this->get_step_x_cart_entries(sprintf("sms_1_sent=0 AND sms_send_errors < 3 AND
        created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));
    }

    public function get_sms_step_2_cart_entries($interval_minutes) {
      return $this->get_step_x_cart_entries(sprintf("sms_1_sent=1 AND sms_2_sent=0 AND sms_send_errors < 3 AND
        created < DATE_SUB(NOW(), INTERVAL %d MINUTE)", $interval_minutes));
    }

    public function get_step_x_cart_entries($where_query) {
      global $wpdb;

      $temp_cart_table_name = $wpdb->prefix . SINHRO_INTEGRATION_CART_TABLE_NAME;

      $results = $wpdb->get_results("SELECT * FROM {$temp_cart_table_name} WHERE ${where_query}");

      return $results;
    }

    // send email 1 15 after order is marked as completed
    // if survey is not completed, send sms 1 24 hours later
    public function process_post_purchase_surveys() {
      global $wpdb;

      // POST_PURCHASE_ENTRIES_TABLE_NAME
      // POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME

      $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;

      $this->check_and_create_db_tables();

      $mandrill_from_address = get_option("ssi_mandrill_from_address");
      $mandrill_api_key = get_option("ssi_mandrill_api_key");

      $email_1_survey_page_url = get_option("ssi_mandrill_post_purchase_email_1_survey_page_url");
      $email_1_subject = get_option("ssi_mandrill_post_purchase_email_1_subject");
      $email_1_message = get_option("ssi_mandrill_post_purchase_email_1_message");
      $email_1_minutes = get_option("ssi_post_purchase_email_1_minutes");

      $sms_1_minutes = get_option("ssi_post_purchase_sms_1_minutes");
      $sms_1_survey_page_url = get_option("ssi_post_purchase_sms_1_survey_page_url");

      // get_post_purchase_email_1_entries
      // get_post_purchase_sms_1_entries

      if (strlen($mandrill_api_key) > 0 && strlen($email_1_survey_page_url) > 0 && strlen($email_1_subject) > 0 && strlen($email_1_message) > 0) {
        $results = $this->get_post_purchase_email_1_entries($email_1_minutes ? $email_1_minutes : 15);

        if ($results && !is_wp_error($results) && count($results) > 0) {
          foreach ($results as $result) {
            $survey_page_url = add_query_arg("ppshash", $result->unique_hash, $email_1_survey_page_url);

            $options['content'] = stripslashes(sprintf($email_1_message, $survey_page_url));

            $this->send_email($result->email_address, $email_1_subject, $options);

            $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET email_1_sent=1 WHERE id=%d", $result->id));
          }
        }
      }

      if (strlen($sms_1_survey_page_url) > 0) {
        $results = $this->get_post_purchase_sms_1_entries($sms_1_minutes ? $sms_1_minutes : 1440);

        if ($results && !is_wp_error($results) && count($results) > 0) {
          foreach ($results as $result) {
            $survey_page_url = add_query_arg("ppshash", $result->unique_hash, $sms_1_survey_page_url);
            $sms_message = sprintf(esc_html__("Your order is complete! Please take a few minutes to do our survey: %s", "sinhro-sms-integration"), $survey_page_url);
            $response = $this->send_sms($result->phone, $sms_message);

            if (!is_wp_error($response) && $response && isset($response["body"]) && $response["body"] == "Result_code: 00, Message OK") {
                $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET sms_1_sent=1 WHERE id=%d", $result->id));
            } else {
                $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET sms_send_errors=sms_send_errors+1 WHERE id=%d", $result->id));
                error_log("Error, sms 1 not sent to $result->phone\n\r", 3, $this->plugin_log_file);
                error_log($sms_message);
                error_log(serialize($response), 3, $this->plugin_log_file);
            }
          }
        }
      }
    }

    // send email 1 15 after checkout screen reached
    // if link from email 1 is not opened, send sms 1 24 hours later
    // if link from sms 1 is not opened send email 2 after another 12 hours
    // if link from email 2 is not opened send sms 2 after another 12 hours
    // if link from sms 2 is not opened send email 3 after 24 hours later
    public function process_abandoned_carts()
    {
        global $wpdb;

        $temp_cart_table_name = $wpdb->prefix . SINHRO_INTEGRATION_CART_TABLE_NAME;

        $this->check_and_create_db_tables();

        $mandrill_from_address = get_option("ssi_mandrill_from_address");
        $mandrill_api_key = get_option("ssi_mandrill_api_key");
        $email_1_subject = get_option("ssi_mandrill_email_1_subject");
        $email_1_message = get_option("ssi_mandrill_email_1_message");
        $email_2_subject = get_option("ssi_mandrill_email_2_subject");
        $email_2_message = get_option("ssi_mandrill_email_2_message");
        $email_3_subject = get_option("ssi_mandrill_email_3_subject");
        $email_3_message = get_option("ssi_mandrill_email_3_message");

        $email_1_minutes = get_option("ssi_email_1_minutes");
        $email_2_minutes = get_option("ssi_email_2_minutes");
        $email_3_minutes = get_option("ssi_email_3_minutes");
        $sms_1_minutes = get_option("ssi_sms_1_minutes");
        $sms_2_minutes = get_option("ssi_sms_2_minutes");

        $options_header_color = get_option("ssi_mandrill_options_header_color");
        $options_footer_color = get_option("ssi_mandrill_options_footer_color");
        $options_header_logo = get_option("ssi_mandrill_options_header_logo");
        $options_footer_logo = get_option("ssi_mandrill_options_footer_logo");
        $options_info_mail = get_option("ssi_mandrill_options_info_mail");

        $options_facebook_url = get_option("ssi_mandrill_options_facebook_url");
        $options_facebook_img = get_option("ssi_mandrill_options_facebook_img");
        $options_instagram_url = get_option("ssi_mandrill_options_instagram_url");
        $options_instagram_img = get_option("ssi_mandrill_options_instagram_img");
        $options_twitter_url = get_option("ssi_mandrill_options_twitter_url");
        $options_twitter_img = get_option("ssi_mandrill_options_twitter_img");

        $options_footer_first_link_url = get_option("ssi_mandrill_options_footer_first_link_url");
        $options_footer_first_link_text = get_option("ssi_mandrill_options_footer_first_link_text");

        $options_footer_second_link_url = get_option("ssi_mandrill_options_footer_second_link_url");
        $options_footer_second_link_text = get_option("ssi_mandrill_options_footer_second_link_text");

        $options = [
          'header_color' => $options_header_color,
          'footer_color' => $options_footer_color,
          'header_logo' => $options_header_logo,
          'footer_logo' => $options_footer_logo,
          'facebook_url' => $options_facebook_url,
          'facebook_img' => $options_facebook_img,
          'instagram_url' => $options_instagram_url,
          'instagram_img' => $options_instagram_img,
          'twitter_url' => $options_twitter_url,
          'twitter_img' => $options_twitter_img,
          'info_mail' => $options_info_mail,
          'sent_by' => $mandrill_from_address,
          'footer_first_link_url' => $options_footer_first_link_url,
          'footer_first_link_text' => $options_footer_first_link_text,
          'footer_second_link_url' => $options_footer_second_link_url,
          'footer_second_link_text' => $options_footer_second_link_text
        ];

        if (strlen($mandrill_api_key) > 0) {

          $results = $this->get_email_step_1_cart_entries($email_1_minutes ? $email_1_minutes : 15);

          if ($results && !is_wp_error($results) && count($results) > 0) {
            foreach ($results as $result) {
              $cart_url = wc_get_cart_url();
              if (!empty(get_option("ssi_mandrill_cart_url_1"))) {
                $cart_url = get_option("ssi_mandrill_cart_url_1");
              }

              $email_1_message = sprintf($email_1_message, $cart_url);
              $options['content'] = stripslashes($email_1_message);
              $this->send_email($result->email_address, $email_1_subject, $options);

              $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET email_1_sent=1 WHERE id=%d", $result->id));
            }
          }
        }

        $results = $this->get_sms_step_1_cart_entries($sms_1_minutes ? $sms_1_minutes : 1440);

        if ($results && !is_wp_error($results) && count($results) > 0) {
          foreach ($results as $result) {
            $cart_url = wc_get_cart_url();
            if (!empty(get_option("ssi_api_cart_url_1"))) {
              $cart_url = get_option("ssi_api_cart_url_1");
            }

            $response = $this->send_sms($result->phone, sprintf(esc_html__("Oops! You left something in your cart! You can finish what you started here: %s", "sinhro-sms-integration"), $cart_url));

            if (!is_wp_error($response) && $response && isset($response["body"]) && $response["body"] == "Result_code: 00, Message OK") {
                $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET sms_1_sent=1 WHERE id=%d", $result->id));
            } else {
                $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET sms_send_errors=sms_send_errors+1 WHERE id=%d", $result->id));
                error_log("Error, sms 1 not sent to $result->phone\n\r", 3, $this->plugin_log_file);
                error_log(serialize($response), 3, $this->plugin_log_file);
            }
          }
        }

        if (strlen($mandrill_api_key) > 0) {
          $results = $this->get_email_step_2_cart_entries($email_2_minutes ? $email_2_minutes : 1920);

          if ($results && !is_wp_error($results) && count($results) > 0) {
            foreach ($results as $result) {
              $cart_url = wc_get_cart_url();
              if (!empty(get_option("ssi_mandrill_cart_url_2"))) {
                $cart_url = get_option("ssi_mandrill_cart_url_2");
              }

              $customer_first_name = isset($result->first_name) ? $result->first_name : "";
              $discount_value = get_option("ssi_api_discount_value") ? get_option("ssi_api_discount_value") : "20";

              $email_2_message = sprintf($email_2_message, $customer_first_name, $discount_value, $cart_url);
              $options['content'] = stripslashes($email_2_message);
              $this->send_email($result->email_address, $email_2_subject, $options);

              $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET email_2_sent=1 WHERE id=%d", $result->id));
            }
          }
        }

        $results = $this->get_sms_step_2_cart_entries($sms_2_minutes ? $sms_2_minutes : 2880);

        if ($results && !is_wp_error($results) && count($results) > 0) {
          foreach ($results as $result) {
            $customer_first_name = isset($result->first_name) ? $result->first_name : "";
            $discount_value = get_option("ssi_api_discount_value") ? get_option("ssi_api_discount_value") : "20";
            $cart_url = wc_get_cart_url();
            $cart_url = add_query_arg("c", `${discount_value}off`, $cart_url);

            if (!empty(get_option("ssi_api_cart_url_2"))) {
              $cart_url = get_option("ssi_api_cart_url_2");
            }

            $response = $this->send_sms($result->phone, sprintf(esc_html__("Hey %s, get %d%% OFF your purchase. Hurry, before it expires: %s", "sinhro-sms-integration"), $customer_first_name, $discount_value, $cart_url));

            if ($response && isset($response["body"]) && $response["body"] == "Result_code: 00, Message OK") {
                $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET sms_2_sent=1 WHERE id=%d", $result->id));
            } else {
                $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET sms_send_errors=sms_send_errors+1 WHERE id=%d", $result->id));
                error_log("Error, sms 2 not sent to $result->phone\n\r", 3, $this->plugin_log_file);
                error_log(serialize($response), 3, $this->plugin_log_file);
            }
          }
        }


        if (strlen($mandrill_api_key) > 0) {
          $results = $this->get_email_step_3_cart_entries($email_3_minutes ? $email_3_minutes : 3840);

          if ($results && !is_wp_error($results) && count($results) > 0) {
            foreach ($results as $result) {
              $customer_first_name = isset($result->first_name) ? $result->first_name : "";

              $cart_url = wc_get_cart_url();
              if (!empty(get_option("ssi_api_cart_url_3"))) {
                $cart_url = get_option("ssi_api_cart_url_3");
              }

              $email_3_message = sprintf($email_3_message, $customer_first_name, $cart_url);
              $options['content'] = stripslashes($email_3_message);
              $this->send_email($result->email_address, $email_3_subject, $options);
              $wpdb->query($wpdb->prepare("UPDATE $temp_cart_table_name SET email_3_sent=1 WHERE id=%d", $result->id));
            }
          }
        }

    }

    public function register_cart_cron_job()
    {
        if (! wp_next_scheduled("ssi_cart_process_sms")) {
            wp_schedule_event(time(), "five_minutes", "ssi_cart_process_sms");
        }

        if (! wp_next_scheduled("ssi_post_purchase_surveys")) {
            wp_schedule_event(time(), "seven_minutes", "ssi_post_purchase_surveys");
        }
    }

    public function woocommerce_review_order_after_submit()
    {
        if (WC()->session) {
            $unique_cart_id = WC()->session->get("cart_unique_id");
            echo "<input type='hidden' id='ssi-unique-cart-id' name='ssi-unique-cart-id' value='$unique_cart_id' />";
        }
    }

    public function wp_enqueue_scripts()
    {
        wp_enqueue_script("sinhro-sms-integration-script", plugin_dir_url(__FILE__) . "js/script.js", array("jquery"), SINHRO_INTEGRATION_VERSION, true);
        wp_localize_script("sinhro-sms-integration-script", "ssiAjax", array( "ajaxurl" => admin_url("admin-ajax.php")));
    }

    public function save_checkout_info()
    {
        global $wpdb;

        $nonce_value = isset($_REQUEST["nonce"]) ? $_REQUEST["nonce"] : "";
        $phone = isset($_REQUEST["phone"]) ? sanitize_text_field($_REQUEST["phone"]) : "";
        $email = isset($_REQUEST["email"]) ? sanitize_text_field($_REQUEST["email"]) : "";
        $first_name = isset($_REQUEST["first_name"]) ? sanitize_text_field($_REQUEST["first_name"]) : "";
        $unique_cart_id = isset($_REQUEST["unique_cart_id"]) ? sanitize_text_field($_REQUEST["unique_cart_id"]) : "";

        if (wp_verify_nonce($nonce_value, "woocommerce-process_checkout")) {
            // nonce passed, we can record the phone number and cart unique id
            $this->check_and_create_db_tables();

            $temp_cart_table_name = $wpdb->prefix . SINHRO_INTEGRATION_CART_TABLE_NAME;

            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$temp_cart_table_name} WHERE abandoned_cart_id=%s", $unique_cart_id));

            $phone = str_replace("+", "", $phone);
            if (substr($phone, 0, strlen("00")) == "00") {
                $phone = substr($phone, strlen("00"));
            }

            if (!$row) {
                $wpdb->query($wpdb->prepare("INSERT INTO $temp_cart_table_name (abandoned_cart_id, phone, email_address, first_name) VALUES (%s, %s, %s, %s)", $unique_cart_id, $phone, $email, $first_name));
            }
        }

        die();
    }

    public function plugin_activate()
    {
        $this->check_and_create_db_tables();
    }

    public function check_and_create_db_tables()
    {
        require_once(ABSPATH . "wp-admin/includes/upgrade.php");

        global $wpdb;

        $wpdb_collate = $wpdb->collate;

        $temp_cart_table_name = $wpdb->prefix . SINHRO_INTEGRATION_CART_TABLE_NAME;

        $sql = "CREATE TABLE {$temp_cart_table_name} (
          id int(11) NOT NULL auto_increment,
          abandoned_cart_id varchar(20) NOT NULL,
          created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          email_1_sent BIT NOT NULL DEFAULT 0,
          email_2_sent BIT NOT NULL DEFAULT 0,
          email_3_sent BIT NOT NULL DEFAULT 0,
          sms_1_sent BIT NOT NULL DEFAULT 0,
          sms_2_sent BIT NOT NULL DEFAULT 0,
          sms_send_errors INT(1) NOT NULL DEFAULT 0,
          phone varchar(20) NOT NULL,
          email_address varchar(100) NOT NULL,
          first_name varchar(100) NOT NULL,
          PRIMARY KEY  (`id`)
        ) COLLATE {$wpdb_collate}";

        dbDelta($sql);

        $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;

        $sql = "CREATE TABLE {$temp_cart_table_name} (
          id int(11) NOT NULL auto_increment,
          order_id varchar(20) NOT NULL,
          product_ids TEXT NOT NULL,
          unique_hash varchar(50) NOT NULL,
          created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          survey_completed BIT NOT NULL DEFAULT 0,
          email_1_sent BIT NOT NULL DEFAULT 0,
          sms_1_sent BIT NOT NULL DEFAULT 0,
          sms_send_errors INT(1) NOT NULL DEFAULT 0,
          phone varchar(20) NOT NULL,
          email_address varchar(100) NOT NULL,
          first_name varchar(100) NOT NULL,
          PRIMARY KEY  (`id`)
        ) COLLATE {$wpdb_collate}";

        dbDelta($sql);

        $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME;

        $sql = "CREATE TABLE {$temp_cart_table_name} (
          id int(11) NOT NULL auto_increment,
          unique_hash varchar(50) NOT NULL,
          order_id varchar(20) NOT NULL,
          product_ids TEXT NOT NULL,
          created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
          question_1_answer INT NOT NULL,
          question_2_answer INT NOT NULL,
          question_3_answer INT NOT NULL,
          question_4_answer INT NOT NULL,
          question_5_answer INT NOT NULL,
          overall_rating FLOAT NOT NULL,
          PRIMARY KEY  (`id`)
        ) COLLATE {$wpdb_collate}";

        dbDelta($sql);
    }

    public function plugin_deactivate()
    {
        global $wpdb;

        $temp_cart_table_name = $wpdb->prefix . SINHRO_INTEGRATION_CART_TABLE_NAME;
        $wpdb->query("DROP TABLE IF EXISTS " . $temp_cart_table_name);

        $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;
        $wpdb->query("DROP TABLE IF EXISTS " . $temp_cart_table_name);
    }

    public function woocommerce_order_processed($order_id)
    {
        global $wpdb;

        if (WC()->session) {
            $this->check_and_create_db_tables();

            $temp_cart_table_name = $wpdb->prefix . SINHRO_INTEGRATION_CART_TABLE_NAME;
            $unique_cart_id = WC()->session->get("cart_unique_id");
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$temp_cart_table_name} WHERE abandoned_cart_id=%s", $unique_cart_id));

            if ($row) {
                $wpdb->query($wpdb->prepare("DELETE FROM " . $temp_cart_table_name . " WHERE abandoned_cart_id=%s", $unique_cart_id));
            }
        }
    }

    public function woocommerce_order_status_changed($order_id, $old_status, $new_status) {
        global $wpdb;
        if ($old_status != "completed" && $new_status == "completed") {
            $order = wc_get_order($order_id);

            if ($order && $order->has_status('completed') ) {
                $order_data = $order->get_data();

                if ($order_data) {
                    $order_billing_first_name = isset($order_data['billing']) && isset($order_data['billing']['first_name']) ? $order_data['billing']['first_name'] : '';
                    $order_billing_email = isset($order_data['billing']) && isset($order_data['billing']['email']) ? $order_data['billing']['email'] : '';
                    $order_billing_phone  = isset($order_data['billing']) && isset($order_data['billing']['phone']) ? $order_data['billing']['phone'] : '';

                    $hashed_product_ids = array();
                    foreach ($order->get_items() as $item_key => $item ) {
                      $product_id   = $item->get_product_id(); // the Product id
                      $hashed_product_ids[] = md5($product_id);
                    }

                    $hashed_product_ids_string = implode(":", $hashed_product_ids);

                    $temp_cart_table_name = $wpdb->prefix . POST_PURCHASE_ENTRIES_TABLE_NAME;
                    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$temp_cart_table_name} WHERE order_id=%s", $order_id));

                    if (!$row || !$row->survey_completed) {
                      $unique_hash = md5($order_id . '_' . date("Y-m-d H:i:s"));

                      $wpdb->query($wpdb->prepare("INSERT INTO $temp_cart_table_name (order_id, product_ids, unique_hash, phone, email_address, first_name) VALUES (%s, %s, %s, %s, %s, %s)", $order_id, $hashed_product_ids_string, $unique_hash, $order_billing_phone, $order_billing_email, $order_billing_first_name));
                    }
                }
            }
        }
    }

    public function woocommerce_init()
    {
        if (is_plugin_active("woocommerce/woocommerce.php") && function_exists("WC")) {
            if (WC()->session) {
                $unique_cart_id = WC()->session->get("cart_unique_id");

                if (is_null($unique_cart_id)) {
                    WC()->session->set("cart_unique_id", uniqid());
                }
            }
        }
    }

    public static function i18n_country_calling_codes()
    {
        $codes = [
            "bg_BG" => "359",
            "bs_BA" => "387",
            "cs_CZ" => "420",
            "de_DE" => "49",
            "el" => "30",
            "es_ES" => "34",
            "fr_FR" => "33",
            "hr" => "385",
            "hu_HU" => "36",
            "it_IT" => "39",
            "pl_PL" => "48",
            "pt_PT" => "351",
            "ro_RO" => "40",
            "sk_SK" => "421",
            "sl_SI" => "386",
            "sr_RS" => "381",
        ];

        return $codes;
    }

    public static function i18n_country_phone_lengths()
    {
        $lengths = [
            "bg_BG" => 9,
            "bs_BA" => 8,
            "cs_CZ" => 9,
            "de_DE" => 11,
            "el" => 10,
            "es_ES" => 9,
            "fr_FR" => 9,
            "hr" => 9,
            "hu_HU" => 9,
            "it_IT" => 9,
            "pl_PL" => 9,
            "pt_PT" => 9,
            "ro_RO" => 10,
            "sk_SK" => 9,
            "sl_SI" => 8,
            "sr_RS" => 9,
        ];

        return $lengths;
    }

    public function i18n_country_calling_code($lcid)
    {
        $codes = self::i18n_country_calling_codes();

        return isset($codes[$lcid]) ? $codes[$lcid] : "386";
    }

    public function i18n_country_phone_length($lcid)
    {
        $lengths = self::i18n_country_phone_lengths();

        return isset($lengths[$lcid]) ? $lengths[$lcid] : 8;
    }

    public function parse_template($options) {
      ob_start();
      include plugin_dir_path(__FILE__) . './templates/default.php';
      return ob_get_clean();
    }

    public function send_email($to_email_address, $email_subject, $options = []) {

        $mandrill_api_key = get_option("ssi_mandrill_api_key");
        $from_email_address = get_option("ssi_mandrill_from_address");

       if (strlen($mandrill_api_key) > 0 && strlen($from_email_address) > 0) {
          $mailchimp = new \MailchimpTransactional\ApiClient();
          $mailchimp->setApiKey($mandrill_api_key);

          $response = $mailchimp->messages->send(
          [
            "message" => [
              "subject" => $email_subject,
              "from_email" => $from_email_address,
              "to" => array(array("email" => $to_email_address)),
              "html" => $this->parse_template($options),
              "auto_html" => true,
            ]
          ]);
      }

      return $response;
    }

    public function send_sms($phone, $text, $override_host = "", $override_i18n = false)
    {
        $response = null;

        if ($phone && $text) {
            $phone = str_replace("+", "", $phone);
            if (substr($phone, 0, strlen("00")) == "00") {
                $phone = substr($phone, strlen("00"));
            }

            if (!$override_i18n) {
                $country_code = $this->i18n_country_calling_code(get_locale());
                if (substr($phone, 0, strlen($country_code)) == $country_code) {
                    // strip the country code as we will add it based on locale instead
                    $phone = substr($phone, strlen($country_code));
                }

                if (substr($phone, 0, 1) == "0") {
                    // if the number starts with 0 like 06112313, remove the 0
                    $phone = substr($phone, 1);
                }

                $country_phone_length = $this->i18n_country_phone_length(get_locale());
                if (strlen($phone) >= $country_phone_length && strlen($phone) <= ($country_phone_length + 2)) {
                    $phone = "00" . $country_code . $phone;

                    $ssi_api_username = get_option("ssi_api_username");
                    $ssi_api_password = get_option("ssi_api_password");

                    if (strlen($ssi_api_password) > 0 && strlen($ssi_api_username) > 0) {
                      $body = array(
                        "username"    => get_option("ssi_api_username"),
                        "password"    => get_option("ssi_api_password"),
                        "text"        => sanitize_text_field($text),
                        "call-number" => sanitize_text_field($phone),
                      );

                      $args = array(
                          "body"        => $body,
                      );

                      $api_host = isset($override_host) && !empty($override_host) ? sanitize_text_field($override_host) : "http://gw.sinhro.si/api/http/";

                      $response = wp_remote_post($api_host, $args);
                    }
                }
            } else {
                $phone = "00" . $phone;

                $ssi_api_username = get_option("ssi_api_username");
                $ssi_api_password = get_option("ssi_api_password");

                if (strlen($ssi_api_password) > 0 && strlen($ssi_api_username) > 0) {
                  $body = array(
                    "username"    => get_option("ssi_api_username"),
                    "password"    => get_option("ssi_api_password"),
                    "text"        => sanitize_text_field($text),
                    "call-number" => sanitize_text_field($phone),
                  );

                  $args = array(
                      "body"        => $body,
                  );

                  $api_host = isset($override_host) && !empty($override_host) ? sanitize_text_field($override_host) : "http://gw.sinhro.si/api/http";

                  $response = wp_remote_post($api_host, $args);
                }
            }
        }

        return $response;
    }

    public function send_test_email()
    {
        if (isset($_POST["ssi_send_test_email"])) {
            if ($this->validate_test_email_post_request()) {
              $test_to_email = $_POST["ssi_test_to_email"];
              $test_email_subject = $_POST["ssi_test_email_subject"];

              $mandrill_from_address = get_option("ssi_mandrill_from_address");

              $options_header_color = get_option("ssi_mandrill_options_header_color");
              $options_footer_color = get_option("ssi_mandrill_options_footer_color");
              $options_header_logo = get_option("ssi_mandrill_options_header_logo");
              $options_footer_logo = get_option("ssi_mandrill_options_footer_logo");
              $options_info_mail = get_option("ssi_mandrill_options_info_mail");

              $options_facebook_url = get_option("ssi_mandrill_options_facebook_url");
              $options_facebook_img = get_option("ssi_mandrill_options_facebook_img");
              $options_instagram_url = get_option("ssi_mandrill_options_instagram_url");
              $options_instagram_img = get_option("ssi_mandrill_options_instagram_img");
              $options_twitter_url = get_option("ssi_mandrill_options_twitter_url");
              $options_twitter_img = get_option("ssi_mandrill_options_twitter_img");

              $options_footer_first_link_url = get_option("ssi_mandrill_options_footer_first_link_url");
              $options_footer_first_link_text = get_option("ssi_mandrill_options_footer_first_link_text");

              $options_footer_second_link_url = get_option("ssi_mandrill_options_footer_second_link_url");
              $options_footer_second_link_text = get_option("ssi_mandrill_options_footer_second_link_text");

              $options_content = $_POST["ssi_test_email_content"];

              $options = [
                'header_color' => $options_header_color,
                'footer_color' => $options_footer_color,
                'header_logo' => $options_header_logo,
                'footer_logo' => $options_footer_logo,
                'facebook_url' => $options_facebook_url,
                'facebook_img' => $options_facebook_img,
                'instagram_url' => $options_instagram_url,
                'instagram_img' => $options_instagram_img,
                'twitter_url' => $options_twitter_url,
                'twitter_img' => $options_twitter_img,
                'content' => stripslashes($options_content),
                'sent_by' => $mandrill_from_address,
                'info_mail' => $options_info_mail,
                'footer_first_link_url' => $options_footer_first_link_url,
                'footer_first_link_text' => $options_footer_first_link_text,
                'footer_second_link_url' => $options_footer_second_link_url,
                'footer_second_link_text' => $options_footer_second_link_text
              ];

              $response = $this->send_email($test_to_email, $test_email_subject, $options);

              if (!is_array($response) && strpos($response, "error") !== false) {
                ?>
                <div class="error notice">
                  <p><?php var_dump($response); ?>
                  </p>
                </div>
                <?php
              } else {
                ?>
                <div class="succes notice">
                  <?php var_dump($response); ?>
                  <p><?php _e("Success: test message successfully sent!", "sinhro-sms-integration"); ?></p>
                </div>
                <?php
              }

            } else { ?>
              <div class="error notice">
                <p><?php _e("There has been an error when trying to send a test SMS. Please make sure all test SMS fields are filled in before attempting to send!", "sinhro-sms-integration"); ?>
                </p>
              </div>
              <?php
            }
        }
    }

    public function validate_test_email_post_request()
    {
        if (!isset($_POST["ssi_test_to_email"]) || empty($_POST["ssi_test_to_email"])) {
          return false;
        }

        if (!isset($_POST["ssi_test_email_subject"]) || empty($_POST["ssi_test_email_subject"])) {
          return false;
        }

        return true;
    }

    public function send_test_sms()
    {
        if (isset($_POST["ssi_api_send_test_sms"])) {
            if ($this->validate_test_sms_post_request()) {
              $ssi_api_host = get_option("ssi_api_host");
              $test_phone = $_POST["ssi_api_test_phone_number"];
              $test_message = $_POST["ssi_api_test_message"];

              $response = $this->send_sms($test_phone, $test_message, $ssi_api_host, true);

              if ($response && isset($response["body"]) && $response["body"] == "Result_code: 00, Message OK") { ?>
                <div class="updated notice">
                  <p><?php _e("Success. Test SMS sent!", "sinhro-sms-integration"); ?></p>
                </div>
              <?php
              } else {
                  error_log(serialize($response), 3, $this->plugin_log_file); ?>
                  <div class="error notice">
                    <p><?php _e("Error. Test SMS failed to send!", "sinhro-sms-integration"); ?></p>
                    <textarea rows="10" style="width:100%;margin-bottom:20px;" disabled><?php print_r($response); ?></textarea>
                    <br />
                  </div>
              <?php
              }
            } else { ?>
              <div class="error notice">
                <p><?php _e("There has been an error when trying to send a test SMS. Please make sure all test SMS fields are filled in before attempting to send!", "sinhro-sms-integration"); ?>
                </p>
              </div>
              <?php
            }
        }
    }

    public function validate_test_sms_post_request()
    {
        if (!isset($_POST["ssi_api_test_message"]) || empty($_POST["ssi_api_test_message"])) {
          return false;
        }

        if (!isset($_POST["ssi_api_test_phone_number"]) || empty($_POST["ssi_api_test_phone_number"])) {
          return false;
        }

        return true;
    }

    public function register_sinhro_sms_integration_settings()
    {
        register_setting("sinhro-times-integration-settings", "ssi_post_purchase_email_1_minutes");
        register_setting("sinhro-times-integration-settings", "ssi_email_1_minutes");
        register_setting("sinhro-times-integration-settings", "ssi_email_2_minutes");
        register_setting("sinhro-times-integration-settings", "ssi_email_3_minutes");
        register_setting("sinhro-times-integration-settings", "ssi_post_purchase_sms_1_minutes");
        register_setting("sinhro-times-integration-settings", "ssi_sms_1_minutes");
        register_setting("sinhro-times-integration-settings", "ssi_sms_2_minutes");

        register_setting("sinhro-times-integration-settings", "ssi_api_test_phone_number");
        register_setting("sinhro-times-integration-settings", "ssi_api_test_message");

        register_setting("sinhro-times-integration-settings", "ssi_test_to_email");
        register_setting("sinhro-times-integration-settings", "ssi_test_email_subject");
        register_setting("sinhro-times-integration-settings", "ssi_test_email_content");

        register_setting("sinhro-sms-integration-settings", "ssi_post_purchase_sms_1_survey_page_url");
        register_setting("sinhro-sms-integration-settings", "ssi_api_cart_url_1");
        register_setting("sinhro-sms-integration-settings", "ssi_api_cart_url_2");
        register_setting("sinhro-sms-integration-settings", "ssi_api_host");
        register_setting("sinhro-sms-integration-settings", "ssi_api_username");
        register_setting("sinhro-sms-integration-settings", "ssi_api_discount_value");
        register_setting("sinhro-sms-integration-settings", "ssi_api_password");

        register_setting("sinhro-email-integration-settings", "ssi_mandrill_cart_url_1");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_cart_url_2");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_cart_url_3");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_api_key");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_from_address");

        register_setting("sinhro-email-integration-settings", "ssi_mandrill_email_1_subject");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_email_1_message");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_post_purchase_email_1_survey_page_url");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_post_purchase_email_1_subject");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_post_purchase_email_1_message");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_email_2_subject");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_email_2_message");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_email_3_subject");
        register_setting("sinhro-email-integration-settings", "ssi_mandrill_email_3_message");

        register_setting("sinhro-post-purchase-settings", "ssi_post_purchase_survey_question_1");
        register_setting("sinhro-post-purchase-settings", "ssi_post_purchase_survey_question_2");
        register_setting("sinhro-post-purchase-settings", "ssi_post_purchase_survey_question_3");
        register_setting("sinhro-post-purchase-settings", "ssi_post_purchase_survey_question_4");
        register_setting("sinhro-post-purchase-settings", "ssi_post_purchase_survey_question_5");

        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_header_color");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_color");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_headline");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_header_logo");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_logo");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_facebook_url");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_facebook_img");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_instagram_url");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_instagram_img");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_twitter_url");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_twitter_img");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_info_mail");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_first_link_url");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_first_link_text");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_second_link_url");
        register_setting("sinhro-email-template-integration-settings", "ssi_mandrill_options_footer_second_link_text");
    }

    public function load_plugin_textdomain()
    {
        $this->check_and_create_db_tables();

        load_plugin_textdomain("sinhro-sms-integration", false, dirname(plugin_basename(__FILE__)) . "/languages");
    }

    public function admin_menu()
    {
        add_menu_page($this->plugin_name, __("Sinhro Integration", "sinhro-sms-integration"), "administrator", $this->plugin_name, array($this, "display_plugin_dashboard" ), "dashicons-admin-network", 20);
    }

    public function display_plugin_dashboard()
    {
        require_once plugin_dir_path(__FILE__) . "/partials/admin-settings.php";
    }

    function hooks_for($hook = "", $return = false)
    {
        global $wp_filter;

        if (empty($hook) || !isset($wp_filter[$hook])) {
            return;
        }

        if ($return) {
            ob_start();
        }

        print "<pre>";
        print_r ($wp_filter[$hook]);
        print "</pre>";

        if ($return) {
            return ob_get_clean();
        }
    }
}

new SinhroIntegration();
