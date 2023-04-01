<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class Tokens_Table extends WP_List_Table
{

    public function __construct()
    {
        parent::__construct(array(
            'singular' => __('token', 'tokens_table'),
            'plural' => __('tokens', 'tokens_table'),
            'ajax' => false,
        ));

        add_action('admin_head', array(&$this, 'admin_header'));
    }

    public function admin_header()
    {
        global $page;
		$page = (wp_verify_nonce(sanitize_text_field(wp_unslash(isset($_GET['page']))))) ? sanitize_key($_GET['page']) : false;
    }

//    public function no_items()
//    {
//        _e('No tokens found.');
//    }

//    public function column_default($item, $column_name)
//    {
//	    return match ( $column_name ) {
//		    'card', 'username', 'token' => $item[ $column_name ],
//		    default => print_r( $item, true ),
//	    };
//    }

    public function get_sortable_columns(): array
    {
       return array(
            'ccard' => array('ccard', false),
            'username' => array('username', false),
            'token' => array('token', false),
        );
    }

    public function get_columns(): array
    {
        return array(
            'cb' => '<input type="checkbox" />',
            'ccard' => geideaTokensCard,
            'username' => geideaTokensUsername,
            'token' => geideaTokensToken,
        );
    }

    public function usort_reorder($a, $b): int
    {
        $orderby = wp_verify_nonce(sanitize_text_field(wp_unslash(isset($_GET['orderby'])))) ? sanitize_key($_GET['orderby']) : 'ccard';

        $order = wp_verify_nonce(sanitize_text_field(wp_unslash(isset($_GET['order'])))) ? sanitize_key($_GET['order']) : 'asc';

        $result = strcmp($a[$orderby], $b[$orderby]);

        return ($order === 'asc') ? $result : -$result;
    }

//    public function column_ccard($item)
//    {
//        $nonce = isset($_REQUEST['_wpnonce']) ? sanitize_key(wp_unslash($_REQUEST['_wpnonce'])) : '';
//
//        if (!wp_verify_nonce($nonce, 'delete_item')) {
//            return;
//        }
//
//        if (isset($_SERVER['REQUEST_URI'])) {
//            $uri_parts = explode('?', sanitize_key(wp_unslash($_SERVER['REQUEST_URI'])), 2);
//
//            $result_url = $uri_parts[0] . "?";
//            foreach ($_GET as $k => $v) {
//                $k = sanitize_key($k);
//                $v = sanitize_key($v);
//
//                if ($k != 'token' && $k != 'action') {
//                    $result_url .= $k . "=" . $v . "&";
//                }
//            }
//            $result_url = rtrim($result_url, "&");
//
//            $actions = array(
//                'delete' => sprintf('<a href="%s&action=%s&token=%s&_wpnonce=%s">Delete</a>', esc_url($result_url), 'delete', sanitize_key($item['ID']), wp_create_nonce('delete_item')),
//            );
//
//            return sprintf('%1$s %2$s', sanitize_text_field($item['ccard']), $this->row_actions($actions));
//        }
//    }



//    public function get_bulk_actions(): array
//    {
//        return array(
//            'delete' => geideaTokensDelete,
//        );
//    }

//    public function column_cb($item): string
//    {
//        return sprintf(
//            '<input type="checkbox" name="delete_token_%s" value="%s" />', $item['ID'], $item['ID']
//        );
//    }

    public function prepare_items()
    {
        //TODO now get_tokens returns all of the tokens
        // may be better to make query to database with offset and limit
        // because we need only n-items(e.g. 10) on the page
        $tokens = WC_Payment_Tokens::get_tokens(
            array(
                'gateway_id' => 'geidea',
            )
        );
        $all_data = [];
        foreach ($tokens as $t) {
            $data = $t->get_data();
            $card = $data['card_type'] . ' ' . geideaTokensEndingIn . ' ' . $data['last4'] . " (" . geideaTokensExpires . " " . $data['expiry_month'] . "/" . $data['expiry_year'] . ")";
            $card = ucfirst($card);

            $user = get_userdata($data['user_id']);
            $all_data[] = array(
                'ID' => $data['id'],
                'ccard' => $card,
                'username' => $user->user_nicename,
                'token' => $data['token'],
            );
        }

        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        usort($all_data, array(&$this, 'usort_reorder'));

        $per_page = 10;
        $current_page = $this->get_pagenum();
        $total_items = count($all_data);

        $page_data = array_slice($all_data, (($current_page - 1) * $per_page), $per_page);
        $this->set_pagination_args(array(
            'total_items' => $total_items,
            'per_page' => $per_page,
        ));

        $this->items = $page_data;
    }
}

function render_tokens_table(): void {
    global $tokensTable;
    $tokensTable = new Tokens_Table();
    $tokensTable->prepare_items();
    $tokensTable->display();
}
