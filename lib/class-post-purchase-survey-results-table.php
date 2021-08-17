<?php

/*************************** LOAD THE BASE CLASS *******************************
 *******************************************************************************
 * The WP_List_Table class isn't automatically available to plugins, so we need
 * to check if it's available and load it if necessary.
 */
if(!class_exists('WP_List_Table')) {
	require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

/************************** CREATE A PACKAGE CLASS *****************************
 *******************************************************************************
 * Create a new list table package that extends the core WP_List_Table class.
 * WP_List_Table contains most of the framework for generating the table, but we
 * need to define and override some methods so that our data can be displayed
 * exactly the way we need it to be.
 *
 * To display this on a page, you will first need to instantiate the class,
 * then call $yourInstance->prepare_items() to handle any data manipulation, then
 * finally call $yourInstance->display() to render the table to the page.
 */
class Post_Purchase_Survey_Results_Admin_List_Table extends WP_List_Table {

	private $options;
	private $lastInsertedID;
	private $date_format;

	/**
	* Constructor, we override the parent to pass our own arguments.
	* We use the parent reference to set some default configs.
	*/
	function __construct() {
		global $status, $page;

		 parent::__construct( array(
			'singular'=> 'result', // Singular label
			'plural' => 'results', // plural label, also this well be one of the table css class
			'ajax'	=> false // We won't support Ajax for this table
		) );

		$this->date_format = get_option('date_format');
	}

	function column_default( $item, $column_name ) {
		return $item->$column_name;
	}

	function column_Id($item) {
		return $item->id;
	}

	function column_Created($item) {
    return date($this->date_format, strtotime($item->created));
	}

	function column_Quality($item) {
		return $item->question_1_answer;
	}

	function column_Delivery($item) {
		return $item->question_2_answer;
	}

	function column_Shipping($item) {
		return $item->question_3_answer;
	}

	function column_CustomerSupport($item) {
		return $item->question_4_answer;
	}

	function column_Recommendation($item) {
		return $item->question_5_answer;
	}

	function column_Overall($item) {
		return $item->overall_rating;
	}

	function column_OrderId($item) {
    return "<a target='_blank' href='" . get_admin_url(get_current_blog_id(), "/post.php?action=edit&post=$item->order_id") . "'>$item->order_id</a>";
	}

  protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );
		}
		?>
		<div class="tablenav <?php echo esc_attr( $which ); ?>">
			<?php
			$this->extra_tablenav( $which );
			$this->pagination( $which );
			?>
			<br class="clear" />
		</div>
		<?php
	}

	function extra_tablenav( $which ) {
		if ( $which == "top" ) {
			// The code that goes before the table is here
		}
		if ( $which == "bottom" ) {
			// The code that goes after the table is there
		}
	}

  function get_db_data($current_blog_id, $productid_filter, $order, $orderby, $paged, $per_page) {
		global $wpdb, $_wp_column_headers;
    $temp_cart_table_name = $wpdb->get_blog_prefix($current_blog_id) . POST_PURCHASE_SURVEY_RESULTS_TABLE_NAME;
    $sql = "SELECT * FROM {$temp_cart_table_name} WHERE 1=1 ";

    $where_sql = "";
    if (!empty($productid_filter)) {
      $where_sql .= " AND product_ids LIKE '%$productid_filter%' ";
    }
    $sql .= $where_sql;

		if(!empty($orderby) & !empty($order)) {
			$sql .= " ORDER BY $orderby $order ";
		}

		if($paged > 0) {
			$offset = ($paged-1) * $per_page;
			$sql .= $wpdb->prepare(" LIMIT %d, %d ", $offset, $per_page);
		} else {
      $sql .= $wpdb->prepare(" LIMIT 0, %d ", $per_page);
    }

    $totalitems = $wpdb->get_var("SELECT COUNT(*) FROM $temp_cart_table_name WHERE 1=1 " . $where_sql);
    $results = $wpdb->get_results($sql);

    return array(
      'totalitems' => $totalitems,
      'results' => $results,
    );
  }

	/**
	 * Define the columns that are going to be used in the table
	 * @return array $columns, the array of columns to use with the table
	 */
	function get_columns() {
		return $columns= array(
			'Id'=>esc_html__('Id', 'sinhro-sms-integration'),
			'Created'=>esc_html__('Created', 'sinhro-sms-integration'),
      'Quality'=>esc_html__('Quality', 'sinhro-sms-integration'),
			'Delivery'=>esc_html__('Delivery', 'sinhro-sms-integration'),
			'Shipping'=>esc_html__('Shipping', 'sinhro-sms-integration'),
			'CustomerSupport'=>esc_html__('Customer Support', 'sinhro-sms-integration'),
			'Recommendation'=>esc_html__('Recommendation', 'sinhro-sms-integration'),
			'Overall'=>esc_html__('Overall', 'sinhro-sms-integration'),
			'OrderId'=>esc_html__('Order Id', 'sinhro-sms-integration'),
		);
	}

	/**
	 * Decide which columns to activate the sorting functionality on
	 * @return array $sortable, the array of columns that can be sorted by the user
	 */
	public function get_sortable_columns() {
		$sortable_columns = array(
			'Id'=> array( 'Id', true ),
			'Created'=> array( 'created', true ),
			'Quality'=> array( 'question_1_answer', true ),
			'Delivery'=> array( 'question_2_answer', true ),
			'Shipping'=> array( 'question_3_answer', true ),
			'CustomerSupport'=> array( 'question_4_answer', true ),
			'Recommendation'=> array( 'question_5_answer', true ),
			'Overall'=> array( 'overall_rating', true ),
			'OrderId'=> array( 'order_id', true ),
		);

		return $sortable_columns;
	}

	/**
	 * Prepare the table with different parameters, pagination, columns and table elements
	 */
	function prepare_items() {

		global $wpdb, $_wp_column_headers;

		$user = get_current_user_id();
		$per_page = 20;

		$search_term = '';
		if (!empty($_REQUEST['s'])) {
			$search_term = esc_sql(strtolower($_REQUEST['s']));
		}

		$columns = $this->get_columns();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, array(), $sortable);

		/* -- Ordering parameters -- */
		//Parameters that are going to be used to order the result
		$orderby = !empty($_GET["orderby"]) ? esc_sql($_GET["orderby"]) : 'Id';
		$order = !empty($_GET["order"]) ? esc_sql($_GET["order"]) : 'ASC';

		/* -- Pagination parameters -- */
		// How many to display per page?
		// Which page is this?
		$paged = !empty($_GET["paged"]) ? esc_sql($_GET["paged"]) : '';
		// Page number
		if(empty($paged) || !is_numeric($paged) || $paged<=0 ) { $paged=1; }

    $productid_filter = !empty($_GET["productid"]) ? esc_sql($_GET["productid"]) : '';

		$author_id = null;
		if (!(current_user_can('editor') || current_user_can('administrator'))) {
			$author_id = get_current_user_id();
		}

    $current_blog_id = get_current_blog_id();
    $db_data = $this->get_db_data( $current_blog_id, $productid_filter, $order, $orderby, $paged, $per_page );
    $results = $db_data['results'];
    $totalitems = $db_data['totalitems'];

    if (is_multisite() && $current_blog_id !== 1 && count($results) > 0) {
      switch_to_blog(1);
      $db_data = $this->get_db_data( 1, $productid_filter, $order, $orderby, $paged, $per_page );
      $results = $db_data['results'];
      $totalitems = $db_data['totalitems'];
      restore_current_blog();
    }

		//How many pages do we have in total?
		$totalpages = ceil($totalitems/$per_page);

		/* -- Register the pagination -- */
		$this->set_pagination_args( array(
			"total_items" => $totalitems,
			"total_pages" => $totalpages,
			"per_page" => $per_page,
		) );
		//The pagination links are automatically built according to those parameters

		/* -- Register the Columns -- */
		$columns = $this->get_columns();

		/* -- Fetch the items -- */
		$this->items = $results;
	}
}
