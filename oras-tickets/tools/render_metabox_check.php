<?php
// Renders the ORAS Events unified metabox for a known post and outputs JSON
// to indicate whether inline fallback CSS/JS and the diagnostic notice are present.

$post_id = 22;
$post = get_post( $post_id );
if ( ! $post ) {
    echo json_encode( [ 'error' => 'post_not_found', 'post_id' => $post_id ] );
    return;
}

if ( ! class_exists( '\\ORAS\\Tickets\\Admin\\Event_Addon_Metabox' ) ) {
    echo json_encode( [ 'error' => 'class_missing' ] );
    return;
}

// Ensure a user with sufficient capability is set for current_user_can() checks
if ( function_exists( 'wp_set_current_user' ) ) {
    wp_set_current_user( 1 );
}

ob_start();
$m = new \ORAS\Tickets\Admin\Event_Addon_Metabox();
$m->render_metabox( $post );
$html = ob_get_clean();

$res = [
    'has_notice' => ( strpos( $html, 'ORAS metabox redesign active' ) !== false ),
    'has_inline_style' => ( strpos( $html, '#oras-events-addon .oras-events-addon__tabs' ) !== false ),
    'has_header' => ( strpos( $html, 'oras-events-addon__header' ) !== false ),
    'snippet_len' => strlen( $html ),
];

echo json_encode( $res );
