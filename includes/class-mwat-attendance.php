<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MWAT_Attendance {
    private static $instance;
    public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

    private function __construct() {
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        add_shortcode( 'mwat_attendance', array( $this, 'portal' ) );
        add_action( 'admin_post_mwat_submit_attendance', array( $this, 'submit_attendance' ) );
        add_action( 'admin_post_nopriv_mwat_submit_attendance', array( $this, 'submit_attendance' ) );
        add_action( 'admin_post_mwat_assign_leader', array( $this, 'assign_leader' ) );
        add_action( 'admin_post_mwat_create_leader', array( $this, 'create_leader' ) );
        add_action( 'admin_post_mwat_save_entry', array( $this, 'save_entry' ) );
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
        return get_posts( array( 'post_type' => 'gd_place', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
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
            foreach ( array( 'dashboard' => 'Dashboard', 'submit' => 'Add Attendance', 'walks' => 'Walk Leaders', 'history' => 'History' ) as $key => $label ) {
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
        echo '<div class="mwat-intro"><h3>This week</h3><p>See which walks still need to send their attendance.</p></div>';
        echo '<div class="mwat-stats"><div><strong>' . count($walks) . '</strong><span>Total walks</span></div><div><strong>' . count($submitted) . '</strong><span>Submitted</span></div><div><strong>' . max(0,count($walks)-count($submitted)) . '</strong><span>Not submitted</span></div><div><strong>' . $total . '</strong><span>Attendees</span></div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head mwat-dashboard-head"><div><h3>Walk status</h3><p>Not submitted walks are shown first.</p></div><input id="mwat-walk-search" class="mwat-search" type="search" placeholder="Search walks..." aria-label="Search walks"></div>';
        echo '<div class="mwat-filters"><button type="button" class="mwat-filter active" data-filter="waiting">Not Submitted <span>'.max(0,count($walks)-count($submitted)).'</span></button><button type="button" class="mwat-filter" data-filter="done">Submitted <span>'.count($submitted).'</span></button><button type="button" class="mwat-filter" data-filter="all">All <span>'.count($walks).'</span></button></div><div id="mwat-walk-list" class="mwat-list mwat-status-grid">';
        if ( ! $walks ) echo '<div class="mwat-empty">No published GeoDirectory walks were found.</div>';
        foreach ( $walks as $walk ) {
            $ok = isset($submitted[$walk->ID]); $leader = get_user_by('id',(int)get_post_meta($walk->ID,'_mwat_leader_user',true));
            echo '<div class="mwat-row mwat-walk-row" data-status="'.($ok?'done':'waiting').'" data-search="'.esc_attr(strtolower($walk->post_title.' '.($leader?$leader->display_name:''))).'"><div><strong>'.esc_html($walk->post_title).'</strong><small>'.esc_html($leader ? $leader->display_name : 'No leader assigned').'</small></div><span class="mwat-status '.($ok?'done':'waiting').'">'.($ok?'Submitted':'Not submitted').'</span></div>';
        }
        echo '</div><div id="mwat-no-results" class="mwat-empty" hidden>No walks match your search.</div></div>';
        echo '<script>(function(){var box=document.getElementById("mwat-walk-search"),rows=[].slice.call(document.querySelectorAll(".mwat-walk-row")),buttons=[].slice.call(document.querySelectorAll(".mwat-filter")),empty=document.getElementById("mwat-no-results"),filter="waiting";function draw(){var q=(box.value||"").toLowerCase().trim(),shown=0;rows.forEach(function(r){var yes=(filter==="all"||r.dataset.status===filter)&&(!q||r.dataset.search.indexOf(q)>-1);r.style.display=yes?"":"none";if(yes)shown++;});empty.hidden=shown>0;}buttons.forEach(function(b){b.addEventListener("click",function(){buttons.forEach(function(x){x.classList.remove("active")});b.classList.add("active");filter=b.dataset.filter;draw();});});box.addEventListener("input",draw);draw();})();</script>';
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
        $users = get_users( array( 'orderby' => 'display_name' ) );
        $walks = $this->walks();
        $assigned = 0;
        foreach ( $walks as $walk ) if ( (int) get_post_meta( $walk->ID, '_mwat_leader_user', true ) ) $assigned++;
        echo '<div class="mwat-intro"><h3>Walk Leaders</h3><p>Create leader access and connect each person to their GeoDirectory walk.</p></div>';
        if ( isset($_GET['mwat_leader_status']) ) {
            $status=sanitize_key(wp_unslash($_GET['mwat_leader_status']));
            if('created'===$status) echo '<div class="mwat-success">✓ Leader account created. You can now assign them to a walk.</div>';
            if('assigned'===$status) echo '<div class="mwat-success">✓ Walk leader assignment saved.</div>';
        }
        echo '<div class="mwat-stats mwat-leader-stats"><div><strong>'.count($walks).'</strong><span>GeoDirectory walks</span></div><div><strong>'.$assigned.'</strong><span>Leader assigned</span></div><div><strong>'.max(0,count($walks)-$assigned).'</strong><span>Need a leader</span></div></div>';
        echo '<div class="mwat-grid mwat-leader-grid"><div>';
        echo '<div class="mwat-card"><h3>Create a leader</h3><p class="mwat-help">Creates a simple WordPress account for attendance access.</p><form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_create_leader">';
        wp_nonce_field('mwat_create_leader','mwat_nonce');
        echo '<label>Name<input name="leader_name" type="text" required></label><label>Email address<input name="leader_email" type="email" required></label><button class="mwat-primary" type="submit">Create Leader</button></form></div>';
        echo '<div class="mwat-card mwat-spaced"><h3>Assign to a walk</h3><p class="mwat-help">A leader can be assigned to an existing GeoDirectory walk.</p><form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_assign_leader">';
        wp_nonce_field('mwat_assign_leader','mwat_nonce');
        echo '<label>Walk<select name="walk_id" required><option value="">Choose a walk</option>';
        foreach($walks as $walk) echo '<option value="'.(int)$walk->ID.'">'.esc_html($walk->post_title).'</option>';
        echo '</select></label><label>Leader<select name="leader_user"><option value="0">No leader assigned</option>';
        foreach($users as $u) echo '<option value="'.(int)$u->ID.'">'.esc_html($u->display_name.' — '.$u->user_email).'</option>';
        echo '</select></label><button class="mwat-primary" type="submit">Save Assignment</button></form></div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head mwat-dashboard-head"><div><h3>Walk assignments</h3><p>Search by walk or leader.</p></div><input id="mwat-leader-search" class="mwat-search" type="search" placeholder="Search walks or leaders..."></div><div class="mwat-filters"><button type="button" class="mwat-leader-filter active" data-filter="unassigned">Need a leader <span>'.max(0,count($walks)-$assigned).'</span></button><button type="button" class="mwat-leader-filter" data-filter="assigned">Assigned <span>'.$assigned.'</span></button><button type="button" class="mwat-leader-filter" data-filter="all">All <span>'.count($walks).'</span></button></div><div class="mwat-list">';
        foreach($walks as $walk) {
            $leader=get_user_by('id',(int)get_post_meta($walk->ID,'_mwat_leader_user',true));
            echo '<div class="mwat-row mwat-leader-row" data-status="'.($leader?'assigned':'unassigned').'" data-search="'.esc_attr(strtolower($walk->post_title.' '.($leader?$leader->display_name.' '.$leader->user_email:''))).'"><div><strong>'.esc_html($walk->post_title).'</strong><small>'.esc_html($leader?$leader->display_name.' — '.$leader->user_email:'No leader assigned').'</small></div><span class="mwat-status '.($leader?'done':'waiting').'">'.($leader?'Assigned':'Needs leader').'</span></div>';
        }
        echo '</div><div id="mwat-leader-empty" class="mwat-empty" hidden>No matching walks found.</div></div></div>';
        echo '<script>(function(){var box=document.getElementById("mwat-leader-search"),rows=[].slice.call(document.querySelectorAll(".mwat-leader-row")),buttons=[].slice.call(document.querySelectorAll(".mwat-leader-filter")),empty=document.getElementById("mwat-leader-empty"),filter="unassigned";function draw(){var q=(box.value||"").toLowerCase().trim(),n=0;rows.forEach(function(r){var show=(filter==="all"||r.dataset.status===filter)&&(!q||r.dataset.search.indexOf(q)>-1);r.style.display=show?"":"none";if(show)n++;});empty.hidden=n>0;}buttons.forEach(function(b){b.addEventListener("click",function(){buttons.forEach(function(x){x.classList.remove("active")});b.classList.add("active");filter=b.dataset.filter;draw();});});box.addEventListener("input",draw);draw();})();</script>';
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

    public function create_leader() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        check_admin_referer('mwat_create_leader','mwat_nonce');
        $name=isset($_POST['leader_name'])?sanitize_text_field(wp_unslash($_POST['leader_name'])):'';
        $email=isset($_POST['leader_email'])?sanitize_email(wp_unslash($_POST['leader_email'])):'';
        if(!$name || !is_email($email)) wp_die('Please enter a valid name and email address.');
        if(email_exists($email)) wp_die('An account already exists with that email address. Use it in the assignment list instead.');
        $base=sanitize_user(strtolower(str_replace(' ','',$name)),true);
        if(!$base) $base='walkleader';
        $login=$base; $i=1;
        while(username_exists($login)){ $login=$base.$i; $i++; }
        $password=wp_generate_password(20,true,true);
        $uid=wp_create_user($login,$password,$email);
        if(is_wp_error($uid)) wp_die(esc_html($uid->get_error_message()));
        wp_update_user(array('ID'=>$uid,'display_name'=>$name,'first_name'=>$name,'role'=>'subscriber'));
        wp_new_user_notification($uid,null,'user');
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'walks','mwat_leader_status'=>'created'),wp_get_referer()?:home_url('/'))); exit;
    }

    public function assign_leader() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        check_admin_referer('mwat_assign_leader','mwat_nonce');
        $walk_id=isset($_POST['walk_id'])?absint($_POST['walk_id']):0;
        $leader_id=isset($_POST['leader_user'])?absint($_POST['leader_user']):0;
        if(!$walk_id || 'gd_place' !== get_post_type($walk_id)) wp_die('Please choose a valid GeoDirectory walk.');
        if($leader_id && !get_user_by('id',$leader_id)) wp_die('Please choose a valid user.');
        update_post_meta($walk_id,'_mwat_leader_user',$leader_id);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'walks','mwat_leader_status'=>'assigned'),wp_get_referer()?:home_url('/'))); exit;
    }

    public function save_entry() {}
}
