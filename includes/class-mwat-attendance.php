<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MWAT_Attendance {
    private static $instance;
    public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

    private function __construct() {
        add_action( 'init', array( $this, 'register_walk_type' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        add_shortcode( 'mwat_attendance', array( $this, 'portal' ) );
        add_action( 'admin_post_mwat_submit_attendance', array( $this, 'submit_attendance' ) );
        add_action( 'admin_post_nopriv_mwat_submit_attendance', array( $this, 'submit_attendance' ) );
        add_action( 'admin_post_mwat_save_walk', array( $this, 'save_walk' ) );
        add_action( 'admin_post_mwat_delete_walk', array( $this, 'delete_walk' ) );
        add_action( 'admin_post_mwat_save_entry', array( $this, 'save_entry' ) );
    }

    public function register_walk_type() {
        register_post_type( 'mwat_walk', array(
            'labels' => array( 'name' => 'MWAT Walks', 'singular_name' => 'MWAT Walk' ),
            'public' => false, 'show_ui' => false, 'supports' => array( 'title' ),
        ) );
    }

    public function assets() {
        if ( ! is_singular() ) return;
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'mwat_attendance' ) ) return;
        wp_enqueue_style( 'mwat-attendance', MWAT_ATTENDANCE_URL . 'assets/mwat-attendance.css', array(), MWAT_ATTENDANCE_VERSION );
    }

    private function is_manager() {
        return is_user_logged_in() && current_user_can( 'manage_options' );
    }

    private function entries() {
        $entries = get_option( 'mwat_attendance_entries', array() );
        return is_array( $entries ) ? $entries : array();
    }

    private function walks() {
        return get_posts( array( 'post_type' => 'mwat_walk', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
    }

    private function assigned_walks() {
        if ( $this->is_manager() ) return $this->walks();
        if ( ! is_user_logged_in() ) return array();
        $uid = get_current_user_id();
        return array_values( array_filter( $this->walks(), function( $walk ) use ( $uid ) {
            return $uid === (int) get_post_meta( $walk->ID, '_mwat_leader_user', true );
        } ) );
    }

    public function portal() {
        if ( ! is_user_logged_in() ) {
            return '<div class="mwat-shell"><div class="mwat-login"><h2>Walk Attendance</h2><p>Please log in to record or manage walk attendance.</p>' . wp_login_form( array( 'echo' => false, 'remember' => true ) ) . '</div></div>';
        }

        $tab = isset( $_GET['mwat_tab'] ) ? sanitize_key( wp_unslash( $_GET['mwat_tab'] ) ) : ( $this->is_manager() ? 'dashboard' : 'submit' );
        ob_start();
        echo '<div class="mwat-shell">';
        $this->header( $tab );
        if ( $this->is_manager() ) {
            if ( 'walks' === $tab ) $this->walks_screen();
            elseif ( 'history' === $tab ) $this->history_screen();
            else $this->dashboard();
        } else {
            $this->leader_screen();
        }
        echo '</div>';
        return ob_get_clean();
    }

    private function header( $tab ) {
        $user = wp_get_current_user();
        echo '<div class="mwat-top"><div><span class="mwat-eyebrow">Men Walking & Talking</span><h2>Attendance</h2></div><div class="mwat-user">Hi, ' . esc_html( $user->display_name ) . '</div></div>';
        if ( $this->is_manager() ) {
            $base = remove_query_arg( 'mwat_tab' );
            echo '<nav class="mwat-nav">';
            foreach ( array( 'dashboard' => 'Dashboard', 'submit' => 'Add Attendance', 'walks' => 'Walks & Leaders', 'history' => 'History' ) as $key => $label ) {
                echo '<a class="' . ( $tab === $key ? 'active' : '' ) . '" href="' . esc_url( add_query_arg( 'mwat_tab', $key, $base ) ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
            if ( 'submit' === $tab ) $this->leader_screen();
        }
    }

    private function dashboard() {
        $walks = $this->walks(); $entries = $this->entries(); $today = wp_date( 'Y-m-d' );
        $week_start = wp_date( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) );
        $submitted = array(); $total = 0;
        foreach ( $entries as $e ) {
            if ( ($e['date'] ?? '') >= $week_start && ($e['date'] ?? '') <= $today ) {
                if ( ! empty( $e['walk_id'] ) ) $submitted[(int)$e['walk_id']] = true;
                $total += (int)($e['attendees'] ?? 0);
            }
        }
        echo '<div class="mwat-intro"><h3>This week</h3><p>See at a glance which walks have sent their attendance.</p></div>';
        echo '<div class="mwat-stats"><div><strong>' . count($walks) . '</strong><span>Total walks</span></div><div><strong>' . count($submitted) . '</strong><span>Submitted</span></div><div><strong>' . max(0,count($walks)-count($submitted)) . '</strong><span>Waiting</span></div><div><strong>' . $total . '</strong><span>Attendees</span></div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head"><h3>Walk status</h3></div><div class="mwat-list">';
        if ( ! $walks ) echo '<div class="mwat-empty">No walks added yet. Open <strong>Walks & Leaders</strong> to add the first one.</div>';
        foreach ( $walks as $walk ) {
            $ok = isset($submitted[$walk->ID]); $leader = get_user_by('id',(int)get_post_meta($walk->ID,'_mwat_leader_user',true));
            echo '<div class="mwat-row"><div><strong>'.esc_html($walk->post_title).'</strong><small>'.esc_html($leader ? $leader->display_name : 'No leader assigned').'</small></div><span class="mwat-status '.($ok?'done':'waiting').'">'.($ok?'Submitted':'Waiting').'</span></div>';
        }
        echo '</div></div>';
    }

    private function leader_screen() {
        $walks = $this->assigned_walks();
        if ( $this->is_manager() ) $walks = $this->walks();
        echo '<div class="mwat-form-card"><div class="mwat-intro"><h3>Record attendance</h3><p>Enter the figures for the walk and press submit.</p></div>';
        if ( isset($_GET['mwat_status']) && 'success' === sanitize_key(wp_unslash($_GET['mwat_status'])) ) echo '<div class="mwat-success">✓ Attendance recorded successfully.</div>';
        if ( ! $walks ) { echo '<div class="mwat-empty">No walk is assigned to this account yet.</div></div>'; return; }
        echo '<form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_submit_attendance">';
        wp_nonce_field('mwat_submit_attendance','mwat_nonce');
        echo '<label>Walk location<select name="walk_id" required><option value="">Choose your walk</option>';
        foreach($walks as $walk) echo '<option value="'.(int)$walk->ID.'">'.esc_html($walk->post_title).'</option>';
        echo '</select></label><label>Date<input name="walk_date" type="date" value="'.esc_attr(wp_date('Y-m-d')).'" required></label>';
        echo '<div class="mwat-numbers"><label>Attendees<input name="attendees" type="number" min="0" inputmode="numeric" required></label><label>New attendees<input name="new_attendees" type="number" min="0" inputmode="numeric" required></label><label>Dogs<input name="dogs" type="number" min="0" inputmode="numeric" required></label></div>';
        echo '<button class="mwat-primary" type="submit">Submit Attendance</button></form></div>';
    }

    private function walks_screen() {
        $users = get_users( array( 'orderby'=>'display_name' ) );
        echo '<div class="mwat-grid"><div class="mwat-card"><h3>Add a walk</h3><form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_save_walk">';
        wp_nonce_field('mwat_save_walk','mwat_nonce');
        echo '<label>Walk name / location<input name="walk_name" type="text" required></label><label>Walk leader<select name="leader_user"><option value="0">Not assigned yet</option>';
        foreach($users as $u) echo '<option value="'.(int)$u->ID.'">'.esc_html($u->display_name.' — '.$u->user_email).'</option>';
        echo '</select></label><button class="mwat-primary" type="submit">Add Walk</button></form></div><div class="mwat-card"><h3>Current walks</h3><div class="mwat-list">';
        foreach($this->walks() as $walk) {
            $leader=get_user_by('id',(int)get_post_meta($walk->ID,'_mwat_leader_user',true));
            $url=wp_nonce_url(admin_url('admin-post.php?action=mwat_delete_walk&walk_id='.$walk->ID),'mwat_delete_walk_'.$walk->ID);
            echo '<div class="mwat-row"><div><strong>'.esc_html($walk->post_title).'</strong><small>'.esc_html($leader?$leader->display_name:'No leader assigned').'</small></div><a class="mwat-text-action" onclick="return confirm(\'Delete this walk?\')" href="'.esc_url($url).'">Delete</a></div>';
        }
        if(!$this->walks()) echo '<div class="mwat-empty">No walks have been added yet.</div>';
        echo '</div></div></div>';
    }

    private function history_screen() {
        $entries=array_reverse($this->entries());
        echo '<div class="mwat-card"><div class="mwat-card-head"><h3>Attendance history</h3><a class="mwat-secondary" href="'.esc_url(add_query_arg('mwat_export','csv')).'">CSV coming next</a></div><div class="mwat-table-wrap"><table class="mwat-table"><thead><tr><th>Date</th><th>Walk</th><th>Attendees</th><th>New</th><th>Dogs</th><th>Submitted by</th></tr></thead><tbody>';
        foreach($entries as $e) {
            $walk=!empty($e['walk_id'])?get_the_title((int)$e['walk_id']):($e['location']??'—');
            $user=!empty($e['user_id'])?get_user_by('id',(int)$e['user_id']):false;
            echo '<tr><td>'.esc_html($e['date']??'').'</td><td>'.esc_html($walk).'</td><td>'.(int)($e['attendees']??0).'</td><td>'.(int)($e['new_attendees']??0).'</td><td>'.(int)($e['dogs']??0).'</td><td>'.esc_html($user?$user->display_name:'Legacy entry').'</td></tr>';
        }
        if(!$entries) echo '<tr><td colspan="6">No attendance has been recorded yet.</td></tr>';
        echo '</tbody></table></div></div>';
    }

    public function submit_attendance() {
        if ( ! is_user_logged_in() ) auth_redirect();
        check_admin_referer('mwat_submit_attendance','mwat_nonce');
        $walk_id=isset($_POST['walk_id'])?absint($_POST['walk_id']):0;
        $date=isset($_POST['walk_date'])?sanitize_text_field(wp_unslash($_POST['walk_date'])):'';
        $att=isset($_POST['attendees'])?absint($_POST['attendees']):0;
        $new=isset($_POST['new_attendees'])?absint($_POST['new_attendees']):0;
        $dogs=isset($_POST['dogs'])?absint($_POST['dogs']):0;
        $allowed=wp_list_pluck($this->assigned_walks(),'ID');
        if(!$walk_id || !in_array($walk_id,$allowed,true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || $new>$att) wp_die('Please check the attendance details.');
        $entries=$this->entries();
        $entries[]=array('walk_id'=>$walk_id,'date'=>$date,'attendees'=>$att,'new_attendees'=>$new,'dogs'=>$dogs,'user_id'=>get_current_user_id(),'submitted_at'=>current_time('mysql'));
        update_option('mwat_attendance_entries',$entries,false);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'submit','mwat_status'=>'success'),wp_get_referer()?:home_url('/'))); exit;
    }

    public function save_walk() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        check_admin_referer('mwat_save_walk','mwat_nonce');
        $name=isset($_POST['walk_name'])?sanitize_text_field(wp_unslash($_POST['walk_name'])):'';
        if(!$name) wp_die('Walk name is required.');
        $id=wp_insert_post(array('post_type'=>'mwat_walk','post_status'=>'publish','post_title'=>$name));
        if($id && !is_wp_error($id)) update_post_meta($id,'_mwat_leader_user',isset($_POST['leader_user'])?absint($_POST['leader_user']):0);
        wp_safe_redirect(add_query_arg('mwat_tab','walks',wp_get_referer()?:home_url('/'))); exit;
    }

    public function delete_walk() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        $id=isset($_GET['walk_id'])?absint($_GET['walk_id']):0;
        check_admin_referer('mwat_delete_walk_'.$id);
        if($id) wp_trash_post($id);
        wp_safe_redirect(add_query_arg('mwat_tab','walks',wp_get_referer()?:home_url('/'))); exit;
    }

    public function save_entry() {}
}
