<?php declare(strict_types = 1);

// odsl-/home/rocco/projects/ORAS-Tickets/plugin
return \PHPStan\Cache\CacheItem::__set_state(array(
   'variableKey' => 'v1-enums',
   'data' => 
  array (
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Support/Logger.php' => 
    array (
      0 => 'df6b27755085c5234ddc1af7504799b3ca1ed12cb6e1e0656b5a1d5c621b2016',
      1 => 
      array (
        0 => 'oras\\tickets\\support\\logger',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\support\\instance',
        1 => 'oras\\tickets\\support\\__construct',
        2 => 'oras\\tickets\\support\\log',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Bootstrap.php' => 
    array (
      0 => 'd80e4c58aa0932a8549b0923ee723060ffab3ccc9b36e70099bb5ae296d340f0',
      1 => 
      array (
        0 => 'oras\\tickets\\bootstrap',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\instance',
        1 => 'oras\\tickets\\__construct',
        2 => 'oras\\tickets\\init',
        3 => 'oras\\tickets\\register_phase1',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Commerce/Woo/Product_Sync.php' => 
    array (
      0 => 'e68010c176827c20bde9293360eb90d7b02a6ebf968eecba96938b04c8d66e51',
      1 => 
      array (
        0 => 'oras\\tickets\\commerce\\woo\\product_sync',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\commerce\\woo\\register',
        1 => 'oras\\tickets\\commerce\\woo\\snapshot_order_item_ticket_meta',
        2 => 'oras\\tickets\\commerce\\woo\\get_ticket_name_for_event_index',
        3 => 'oras\\tickets\\commerce\\woo\\is_valid_mapped_product',
        4 => 'oras\\tickets\\commerce\\woo\\get_or_create_product',
        5 => 'oras\\tickets\\commerce\\woo\\on_save_event',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Commerce/Woo/Capacity_Consumption.php' => 
    array (
      0 => 'a7802de5c44a031cf5aa07e1807271bf833b3fdd19cf6e07c6dc6ca7913e768b',
      1 => 
      array (
        0 => 'oras\\tickets\\commerce\\woo\\capacity_consumption',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\commerce\\woo\\register',
        1 => 'oras\\tickets\\commerce\\woo\\handle_paid_order',
        2 => 'oras\\tickets\\commerce\\woo\\handle_restore_order',
        3 => 'oras\\tickets\\commerce\\woo\\sync_product_stock',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Commerce/Woo/Cart_Pricing.php' => 
    array (
      0 => '058b5f7548430d448b7d9ba8a536ca4ff6662994da06dfb642844935e6335ccc',
      1 => 
      array (
        0 => 'oras\\tickets\\commerce\\woo\\cart_pricing',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\commerce\\woo\\register',
        1 => 'oras\\tickets\\commerce\\woo\\apply_cart_pricing',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Frontend/Print_Ticket_View.php' => 
    array (
      0 => '3a3551ef7e303ef3759c14f58fd77f48929ef801ea35bf5f9dbd4a5855d406c4',
      1 => 
      array (
      ),
      2 => 
      array (
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Frontend/Tickets_Display.php' => 
    array (
      0 => 'f3fb7db4210e57c4ffd4ea8d140707ee0bfd9c2171117bbd19d3bd49ef26758d',
      1 => 
      array (
        0 => 'oras\\tickets\\frontend\\tickets_display',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\frontend\\instance',
        1 => 'oras\\tickets\\frontend\\__construct',
        2 => 'oras\\tickets\\frontend\\init',
        3 => 'oras\\tickets\\frontend\\enqueue_assets',
        4 => 'oras\\tickets\\frontend\\revalidate_cart_items',
        5 => 'oras\\tickets\\frontend\\get_ticket_definition',
        6 => 'oras\\tickets\\frontend\\get_ticket_name',
        7 => 'oras\\tickets\\frontend\\is_ticket_on_sale_now',
        8 => 'oras\\tickets\\frontend\\get_mapped_product_id',
        9 => 'oras\\tickets\\frontend\\the_content_filter',
        10 => 'oras\\tickets\\frontend\\render_form_html',
        11 => 'oras\\tickets\\frontend\\handle_post',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Frontend/Ticket_Print_Controller.php' => 
    array (
      0 => '466ba9567a61b177819060eb369e2b0376f6a66025a8ce52b8c89ccbd44d70f3',
      1 => 
      array (
        0 => 'oras\\tickets\\frontend\\ticket_print_controller',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\frontend\\instance',
        1 => 'oras\\tickets\\frontend\\__construct',
        2 => 'oras\\tickets\\frontend\\init',
        3 => 'oras\\tickets\\frontend\\register_query_vars',
        4 => 'oras\\tickets\\frontend\\register_rewrite',
        5 => 'oras\\tickets\\frontend\\maybe_render_print_page',
        6 => 'oras\\tickets\\frontend\\is_print_request',
        7 => 'oras\\tickets\\frontend\\get_request_int',
        8 => 'oras\\tickets\\frontend\\deny',
        9 => 'oras\\tickets\\frontend\\get_oras_items_for_event',
        10 => 'oras\\tickets\\frontend\\get_item_ticket_context',
        11 => 'oras\\tickets\\frontend\\get_ticket_name_from_collection',
        12 => 'oras\\tickets\\frontend\\get_event_start',
        13 => 'oras\\tickets\\frontend\\format_event_start',
        14 => 'oras\\tickets\\frontend\\format_price',
        15 => 'oras\\tickets\\frontend\\render_page',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Reports_Aggregator.php' => 
    array (
      0 => '0e612a173b713d9367400f9425b5c00456ebd69a5164d5cb7e1a72e974f6dce3',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\reports_aggregator',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\get_aggregates',
        1 => 'oras\\tickets\\admin\\iterate_order_items',
        2 => 'oras\\tickets\\admin\\iterate_orders',
        3 => 'oras\\tickets\\admin\\get_item_ticket_context',
        4 => 'oras\\tickets\\admin\\normalize_statuses',
        5 => 'oras\\tickets\\admin\\get_cache_key',
        6 => 'oras\\tickets\\admin\\build_cache_key',
        7 => 'oras\\tickets\\admin\\sort_filter_array',
        8 => 'oras\\tickets\\admin\\is_list_array',
        9 => 'oras\\tickets\\admin\\build_date_created_arg',
        10 => 'oras\\tickets\\admin\\get_presale_key_map',
        11 => 'oras\\tickets\\admin\\get_phase_snapshot',
        12 => 'oras\\tickets\\admin\\phase_start_to_timestamp',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Pages/Settings_Page.php' => 
    array (
      0 => '63a76fedf4b57d5e142927e5b454587c940ceaaeff0ba1c8cd154fd7c45cfd90',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\pages\\settings_page',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\pages\\render',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Pages/Reports_Page.php' => 
    array (
      0 => '692a2bfcaed3ae6f3f36780a703fe5337218b11c2008e04110afc0e0c38ffdec',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\pages\\reports_page',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\pages\\render',
        1 => 'oras\\tickets\\admin\\pages\\export_csv',
        2 => 'oras\\tickets\\admin\\pages\\get_date_range_from_request',
        3 => 'oras\\tickets\\admin\\pages\\get_events_with_tickets',
        4 => 'oras\\tickets\\admin\\pages\\get_selected_statuses',
        5 => 'oras\\tickets\\admin\\pages\\get_status_options',
        6 => 'oras\\tickets\\admin\\pages\\format_money',
        7 => 'oras\\tickets\\admin\\pages\\get_event_summary_rows',
        8 => 'oras\\tickets\\admin\\pages\\get_event_summary_cache_key',
        9 => 'oras\\tickets\\admin\\pages\\build_cache_key',
        10 => 'oras\\tickets\\admin\\pages\\sort_filter_array',
        11 => 'oras\\tickets\\admin\\pages\\is_list_array',
        12 => 'oras\\tickets\\admin\\pages\\normalize_statuses',
        13 => 'oras\\tickets\\admin\\pages\\get_overview_statuses',
        14 => 'oras\\tickets\\admin\\pages\\get_overview_scope_from_statuses',
        15 => 'oras\\tickets\\admin\\pages\\build_date_created_arg',
        16 => 'oras\\tickets\\admin\\pages\\get_event_date_display',
        17 => 'oras\\tickets\\admin\\pages\\pick_first_phase_key',
        18 => 'oras\\tickets\\admin\\pages\\build_event_report_url',
        19 => 'oras\\tickets\\admin\\pages\\get_presale_key_map',
        20 => 'oras\\tickets\\admin\\pages\\parse_site_datetime',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Pages/Dashboard_Page.php' => 
    array (
      0 => '259a4241713012c1c19fc0626039c825b8e07cbce772756f142d1c598dde1ab7',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\pages\\dashboard_page',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\pages\\render',
        1 => 'oras\\tickets\\admin\\pages\\get_events_with_tickets',
        2 => 'oras\\tickets\\admin\\pages\\has_sold_out_limited_ticket',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Tickets_Metabox.php' => 
    array (
      0 => '6046149b518fe8312e95cfd583ea0e953ffbc10fa79dae86bd4ac22e07a393cf',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\tickets_metabox',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\instance',
        1 => 'oras\\tickets\\admin\\__construct',
        2 => 'oras\\tickets\\admin\\init',
        3 => 'oras\\tickets\\admin\\enqueue_assets',
        4 => 'oras\\tickets\\admin\\register_metabox',
        5 => 'oras\\tickets\\admin\\render_metabox',
        6 => 'oras\\tickets\\admin\\get_remaining_for_ticket',
        7 => 'oras\\tickets\\admin\\save_post',
        8 => 'oras\\tickets\\admin\\maybe_show_phase_key_notice',
        9 => 'oras\\tickets\\admin\\normalize_price_phase_keys',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Speaker_CPT.php' => 
    array (
      0 => '1f2866efd6305c7d47e54515b00e3e0a451b1f89501bea946fe08218a7ae7b29',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\speaker_cpt',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\register',
        1 => 'oras\\tickets\\admin\\register_post_type',
        2 => 'oras\\tickets\\admin\\register_metabox',
        3 => 'oras\\tickets\\admin\\render_metabox',
        4 => 'oras\\tickets\\admin\\save_post',
        5 => 'oras\\tickets\\admin\\update_or_delete_meta',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Admin/Admin_Menu.php' => 
    array (
      0 => 'eb12f06630a8cfa706195d93b7ce92c84fc9b08967b641248e78b15bf357e67a',
      1 => 
      array (
        0 => 'oras\\tickets\\admin\\admin_menu',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\admin\\register',
        1 => 'oras\\tickets\\admin\\register_menu',
        2 => 'oras\\tickets\\admin\\render_dashboard',
        3 => 'oras\\tickets\\admin\\render_reports',
        4 => 'oras\\tickets\\admin\\render_settings',
        5 => 'oras\\tickets\\admin\\handle_export_csv',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Api/Member_Hub_Tickets.php' => 
    array (
      0 => 'e36def364dcf996a4183cc7928d7439e0caed4ddb45f2492785415ad946f7275',
      1 => 
      array (
        0 => 'oras\\tickets\\api\\member_hub_tickets',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\api\\register',
        1 => 'oras\\tickets\\api\\register_routes',
        2 => 'oras\\tickets\\api\\get_my_tickets',
        3 => 'oras\\tickets\\api\\get_my_tickets_summary',
        4 => 'oras\\tickets\\api\\sanitize_scope',
        5 => 'oras\\tickets\\api\\sanitize_group_by',
        6 => 'oras\\tickets\\api\\get_allowed_statuses',
        7 => 'oras\\tickets\\api\\get_item_event_id',
        8 => 'oras\\tickets\\api\\get_ticket_groups',
        9 => 'oras\\tickets\\api\\group_items_by_event',
        10 => 'oras\\tickets\\api\\get_event_start',
        11 => 'oras\\tickets\\api\\matches_scope',
        12 => 'oras\\tickets\\api\\bucket_event',
        13 => 'oras\\tickets\\api\\get_paged_orders',
        14 => 'oras\\tickets\\api\\get_all_orders',
        15 => 'oras\\tickets\\api\\get_order_args',
        16 => 'oras\\tickets\\api\\get_order_view_url',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Ticket_Collection.php' => 
    array (
      0 => 'ad02569518464d4210ab38521ab8d7825d793efae37cd38007f36216a20b0fb1',
      1 => 
      array (
        0 => 'oras\\tickets\\domain\\ticket_collection',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\domain\\__construct',
        1 => 'oras\\tickets\\domain\\load_for_event',
        2 => 'oras\\tickets\\domain\\load_envelope_for_event',
        3 => 'oras\\tickets\\domain\\save_for_event',
        4 => 'oras\\tickets\\domain\\all',
        5 => 'oras\\tickets\\domain\\count',
        6 => 'oras\\tickets\\domain\\is_empty',
        7 => 'oras\\tickets\\domain\\generate_ticket_key',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Pricing/Price_Resolver.php' => 
    array (
      0 => 'dafc86c295a9f869c33d6ccc2c4ccb80849b0003117f87ef821b9ff434647797',
      1 => 
      array (
        0 => 'oras\\tickets\\domain\\pricing\\price_resolver',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\domain\\pricing\\resolve_ticket_price',
        1 => 'oras\\tickets\\domain\\pricing\\parse_utc_datetime_to_ts',
        2 => 'oras\\tickets\\domain\\pricing\\normalize_price_string',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Meta.php' => 
    array (
      0 => 'ab80ed55ee588042901cde874867fe946daeaef9ac7c525df06b01215eaedc30',
      1 => 
      array (
        0 => 'oras\\tickets\\domain\\meta',
      ),
      2 => 
      array (
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/includes/Domain/Ticket.php' => 
    array (
      0 => 'b40feb095d4c612746ad63e45ba389451fda376994598e8f99de61a4f0df9729',
      1 => 
      array (
        0 => 'oras\\tickets\\domain\\ticket',
      ),
      2 => 
      array (
        0 => 'oras\\tickets\\domain\\__construct',
        1 => 'oras\\tickets\\domain\\to_array',
      ),
      3 => 
      array (
      ),
    ),
    '/home/rocco/projects/ORAS-Tickets/plugin/oras-tickets.php' => 
    array (
      0 => '467e488f91ddaabb477d96a31788e48c52d58920289a8bd204af41be4a5e312d',
      1 => 
      array (
      ),
      2 => 
      array (
      ),
      3 => 
      array (
        0 => 'ORAS_TICKETS_VERSION',
        1 => 'ORAS_TICKETS_FILE',
        2 => 'ORAS_TICKETS_DIR',
        3 => 'ORAS_TICKETS_URL',
        4 => 'ORAS_TICKETS_DEBUG',
      ),
    ),
  ),
));