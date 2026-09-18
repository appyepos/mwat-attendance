<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MWAT_Attendance {
    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode( 'mwat_attendance', array( $this, 'render_form' ) );
        add_action( 'admin_post_mwat_submit_attendance', array( $this, 'handle_submission' ) );
        add_action( 'admin_post_nopriv_mwat_submit_attendance', array( $this, 'handle_submission' ) );
    }

    public function render_form() {
        ob_start();
        if ( isset( $_GET['mwat_status'] ) && 'success' === sanitize_key( wp_unslash( $_GET['mwat_status'] ) ) ) {
            echo '<div class="mwat-attendance__success">Thanks — attendance has been recorded.</div>';
        }
        ?>
        <form class="mwat-attendance" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="mwat_submit_attendance">
            <?php wp_nonce_field( 'mwat_submit_attendance', 'mwat_nonce' ); ?>
            <p><label for="mwat_walk_location">Walk location</label><input id="mwat_walk_location" name="walk_location" type="text" required></p>
            <p><label for="mwat_walk_date">Date</label><input id="mwat_walk_date" name="walk_date" type="date" value="<?php echo esc_attr( wp_date( 'Y-m-d' ) ); ?>" required></p>
            <p><label for="mwat_attendees">How many attendees?</label><input id="mwat_attendees" name="attendees" type="number" min="0" step="1" required></p>
            <p><label for="mwat_new_attendees">How many new attendees?</label><input id="mwat_new_attendees" name="new_attendees" type="number" min="0" step="1" required></p>
            <p><label for="mwat_dogs">How many dogs?</label><input id="mwat_dogs" name="dogs" type="number" min="0" step="1" required></p>
            <p><button type="submit">Submit attendance</button></p>
        </form>
        <?php
        return ob_get_clean();
    }

    public function handle_submission() {
        if ( ! isset( $_POST['mwat_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mwat_nonce'] ) ), 'mwat_submit_attendance' ) ) {
            wp_die( esc_html__( 'Security check failed.', 'mwat-attendance' ) );
        }

        $location      = isset( $_POST['walk_location'] ) ? sanitize_text_field( wp_unslash( $_POST['walk_location'] ) ) : '';
        $date          = isset( $_POST['walk_date'] ) ? sanitize_text_field( wp_unslash( $_POST['walk_date'] ) ) : '';
        $attendees     = isset( $_POST['attendees'] ) ? absint( $_POST['attendees'] ) : 0;
        $new_attendees = isset( $_POST['new_attendees'] ) ? absint( $_POST['new_attendees'] ) : 0;
        $dogs          = isset( $_POST['dogs'] ) ? absint( $_POST['dogs'] ) : 0;

        if ( '' === $location || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $new_attendees > $attendees ) {
            wp_die( esc_html__( 'Please check the attendance details and try again.', 'mwat-attendance' ) );
        }

        $entries = get_option( 'mwat_attendance_entries', array() );
        if ( ! is_array( $entries ) ) {
            $entries = array();
        }

        $entries[] = array(
            'location'      => $location,
            'date'          => $date,
            'attendees'     => $attendees,
            'new_attendees' => $new_attendees,
            'dogs'          => $dogs,
            'submitted_at'  => current_time( 'mysql' ),
        );

        update_option( 'mwat_attendance_entries', $entries, false );

        $redirect = wp_get_referer() ? wp_get_referer() : home_url( '/' );
        wp_safe_redirect( add_query_arg( 'mwat_status', 'success', $redirect ) );
        exit;
    }
}
