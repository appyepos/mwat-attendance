<?php
if ( ! defined( 'ABSPATH' ) ) exit;

final class MWAT_Attendance {
    private static $instance;
    public static function instance() { return self::$instance ?: ( self::$instance = new self() ); }

    private function __construct() {
        add_action( 'init', array( $this, 'ensure_walk_leader_role' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
        add_shortcode( 'mwat_attendance', array( $this, 'portal' ) );
        add_action( 'admin_post_mwat_submit_attendance', array( $this, 'submit_attendance' ) );
        add_action( 'admin_post_nopriv_mwat_submit_attendance', array( $this, 'submit_attendance' ) );
        add_action( 'admin_post_mwat_assign_leader', array( $this, 'assign_leader' ) );
        add_action( 'admin_post_mwat_create_leader', array( $this, 'create_leader' ) );
        add_action( 'admin_post_mwat_regenerate_leader_link', array( $this, 'regenerate_leader_link' ) );
        add_action( 'admin_post_mwat_send_test_reminder', array( $this, 'send_test_reminder' ) );
        add_action( 'admin_post_mwat_save_entry', array( $this, 'save_entry' ) );
        add_action( 'admin_post_mwat_import_test_leaders', array( $this, 'import_test_leaders' ) );
        add_action( 'admin_post_mwat_delete_test_leaders', array( $this, 'delete_test_leaders' ) );
        add_action( 'admin_post_mwat_seed_test_attendance', array( $this, 'seed_test_attendance' ) );
        add_action( 'admin_post_mwat_seed_demo_week', array( $this, 'seed_demo_week' ) );
        add_action( 'admin_post_mwat_seed_current_demo_week', array( $this, 'seed_current_demo_week' ) );
        add_action( 'admin_post_mwat_export_csv', array( $this, 'export_csv' ) );
    }

    public function ensure_walk_leader_role() {
        $caps = array( 'read' => true, 'mwat_submit_attendance' => true );
        if ( class_exists( 'WooCommerce' ) ) {
            $customer = get_role( 'customer' );
            if ( $customer ) $caps = array_merge( $customer->capabilities, $caps );
        }
        $role = get_role( 'walk_leader' );
        if ( ! $role ) {
            add_role( 'walk_leader', 'Walk Leader', $caps );
        } else {
            foreach ( $caps as $cap => $grant ) if ( $grant ) $role->add_cap( $cap );
            $role->add_cap( 'mwat_submit_attendance' );
        }
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

    private function week_bounds( $date = '' ) {
        $ts = $date ? strtotime( $date . ' 12:00:00' ) : current_time( 'timestamp' );
        return array(
            wp_date( 'Y-m-d', strtotime( 'monday this week', $ts ) ),
            wp_date( 'Y-m-d', strtotime( 'sunday this week', $ts ) ),
        );
    }

    private function weekly_entry_index( $walk_id, $date, $entries ) {
        list( $start, $end ) = $this->week_bounds( $date );
        foreach ( $entries as $i => $entry ) {
            if ( (int)($entry['walk_id'] ?? 0) === (int)$walk_id && ($entry['date'] ?? '') >= $start && ($entry['date'] ?? '') <= $end ) return $i;
        }
        return false;
    }

    private function walks() {
        return get_posts( array( 'post_type' => 'gd_place', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
    }

    private function assigned_walks() {
        if ( $this->is_manager() ) return $this->walks();
        if ( ! is_user_logged_in() || ! current_user_can( 'mwat_submit_attendance' ) ) return array();
        $uid = get_current_user_id();
        return array_values( array_filter( $this->walks(), function( $walk ) use ( $uid ) {
            return $uid === (int) get_post_meta( $walk->ID, '_mwat_leader_user', true );
        } ) );
    }

    private function leader_token( $user_id, $regenerate=false ) {
        $token=$regenerate?'':(string)get_user_meta($user_id,'_mwat_attendance_token',true);
        if(!$token){$token=bin2hex(random_bytes(24));update_user_meta($user_id,'_mwat_attendance_token',$token);}
        return $token;
    }

    private function leader_by_token( $token ) {
        if(!preg_match('/^[a-f0-9]{48}$/',$token)) return false;
        $users=get_users(array('meta_key'=>'_mwat_attendance_token','meta_value'=>$token,'number'=>1));
        return $users?reset($users):false;
    }

    private function leader_walk( $user_id ) {
        foreach($this->walks() as $walk) if((int)get_post_meta($walk->ID,'_mwat_leader_user',true)===(int)$user_id) return $walk;
        return false;
    }

    private function attendance_page_url() {
        $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>20,'s'=>'Walk Attendance'));
        foreach($pages as $page) if(has_shortcode($page->post_content,'mwat_attendance')) return get_permalink($page->ID);
        $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>-1));
        foreach($pages as $page) if(has_shortcode($page->post_content,'mwat_attendance')) return get_permalink($page->ID);
        return home_url('/walk-attendance/');
    }

    private function leader_link( $user_id ) {
        return add_query_arg('mwat_key',$this->leader_token($user_id),$this->attendance_page_url());
    }

    private function send_leader_welcome( $user_id, $walk_id ) {
        $user=get_user_by('id',$user_id); if(!$user||!$walk_id) return false;
        $walk=get_post($walk_id); if(!$walk) return false;
        $link=$this->leader_link($user_id);
        $subject='Your MWAT weekly attendance link';
        $message="Hi {$user->display_name},\n\nYou have been set up as the Walk Leader for {$walk->post_title}.\n\nEach week, after your walk, please use your personal link below to send your attendance numbers:\n\n{$link}\n\nPlease save or bookmark this link. You will use the same link every week.\n\nYou will be asked for the walk date, whether the walk took place, attendees, new attendees and dogs. If the walk is cancelled, please still submit the form and select the cancellation reason.\n\nThank you for supporting Men Walking & Talking.";
        return wp_mail($user->user_email,$subject,$message);
    }

    public function portal() {
        $token=isset($_GET['mwat_key'])?sanitize_text_field(wp_unslash($_GET['mwat_key'])):'';
        if($token){
            $leader=$this->leader_by_token($token);
            if(!$leader) return '<div class="mwat-shell"><div class="mwat-login"><h2>Walk Attendance</h2><p>This attendance link is no longer valid. Please contact MWAT for a new link.</p></div></div>';
            ob_start();echo '<div class="mwat-shell">';$this->public_leader_screen($leader,$token);echo '</div>';return ob_get_clean();
        }
        if ( ! is_user_logged_in() ) {
            return '<div class="mwat-shell"><div class="mwat-login"><h2>Walk Attendance</h2><p>This page is for MWAT managers. Walk Leaders should use their personal attendance link.</p>' . wp_login_form( array( 'echo' => false, 'remember' => true ) ) . '</div></div>';
        }

        $tab = isset( $_GET['mwat_tab'] ) ? sanitize_key( wp_unslash( $_GET['mwat_tab'] ) ) : ( $this->is_manager() ? 'dashboard' : 'submit' );
        ob_start();
        echo '<div class="mwat-shell">';
        $this->header( $tab );
        if ( $this->is_manager() ) {
            if ( 'walks' === $tab ) $this->walks_screen();
            elseif ( 'history' === $tab ) $this->history_screen();
            elseif ( 'analytics' === $tab ) $this->analytics_screen();
            elseif ( 'how' === $tab ) $this->how_it_works_screen();
            elseif ( 'admin' === $tab ) $this->admin_screen();
            elseif ( 'submit' === $tab ) $this->leader_screen();
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
            foreach ( array( 'dashboard' => 'Dashboard', 'submit' => 'Add Attendance', 'walks' => 'Walk Leaders', 'history' => 'History', 'analytics' => 'Analytics', 'how' => 'How It Works', 'admin' => 'Admin' ) as $key => $label ) {
                echo '<a class="' . ( $tab === $key ? 'active' : '' ) . '" href="' . esc_url( add_query_arg( 'mwat_tab', $key, $base ) ) . '">' . esc_html( $label ) . '</a>';
            }
            echo '</nav>';
        }
    }

    private function how_it_works_screen() {
        echo '<div class="mwat-intro"><h3>How It Works</h3><p>A simple weekly attendance process designed to reduce manual checking and chasing.</p></div>';
        echo '<div class="mwat-card mwat-how-card"><h3>Weekly attendance</h3><ul><li>Each Walk Leader has their own secure personal attendance link — no weekly login is needed.</li><li>After the walk, the leader submits the walk date, total attendees, new attendees and dogs.</li><li>If a walk is cancelled, the leader records the cancellation and reason instead.</li><li>The dashboard immediately shows which walks have submitted, which were cancelled and which are still outstanding.</li><li>Email reminders can be automated to send a set number of days after the walk, or on a chosen day of the week.</li><li>A reminder can also be sent manually at any time using the <strong>Send Reminder</strong> button on the dashboard.</li></ul></div>';
        echo '<div class="mwat-card mwat-how-card"><h3>Automatic walk management</h3><ul><li>Walk groups are taken directly from the walks already managed on the MWAT website.</li><li>When a new walk is added to the website, it automatically becomes available in the attendance system — there is no separate group list to maintain.</li><li>A Walk Leader is assigned to the group once, and their personal link can then be used every week.</li></ul></div>';
        echo '<div class="mwat-card mwat-how-card"><h3>Reporting &amp; oversight</h3><ul><li>History keeps previous weekly submissions together in one place and allows corrections where required.</li><li>Reports can be filtered by date, walk and status and downloaded as a CSV.</li><li>Analytics show attendance, new attendees, cancellations and group growth over time.</li></ul></div>';
    }

    private function dashboard() {
        $walks=$this->walks(); $entries=$this->entries(); list($week_start,$week_end)=$this->week_bounds();
        $states=array(); $total=0; $submitted=0; $cancelled=0;
        foreach($entries as $entry) {
            if(($entry['date']??'')<$week_start || ($entry['date']??'')>$week_end || empty($entry['walk_id'])) continue;
            $wid=(int)$entry['walk_id']; $type=($entry['type']??'attendance');
            $states[$wid]=$entry;
        }
        foreach($states as $entry) {
            if(($entry['type']??'attendance')==='cancelled') $cancelled++;
            else { $submitted++; $total+=(int)($entry['attendees']??0); }
        }
        $waiting=max(0,count($walks)-count($states));
        if(isset($_GET['mwat_reminder_status'])){$rs=sanitize_key(wp_unslash($_GET['mwat_reminder_status']));if('sent'===$rs)echo '<div class="mwat-success">✓ Test reminder sent to ben_owen@msn.com.</div>';elseif('failed'===$rs)echo '<div class="mwat-empty">The test reminder email could not be sent.</div>';}
        echo '<div class="mwat-intro"><h3>This week</h3><p>Every walk should send one response each week, including cancelled walks.</p></div>'; 
        echo '<div class="mwat-stats"><div><strong>'.count($walks).'</strong><span>Total walks</span></div><div><strong>'.$submitted.'</strong><span>Submitted</span></div><div><strong>'.$cancelled.'</strong><span>Cancelled</span></div><div><strong>'.$waiting.'</strong><span>Not submitted</span></div><div><strong>'.$total.'</strong><span>Attendees</span></div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head mwat-dashboard-head"><div><h3>Walk status</h3><p>All walks for the current week.</p></div><input id="mwat-walk-search" class="mwat-search" type="search" placeholder="Search walks..." aria-label="Search walks"></div>';
        echo '<div class="mwat-filters"><button type="button" class="mwat-filter active" data-filter="all">All <span>'.count($walks).'</span></button><button type="button" class="mwat-filter" data-filter="waiting">Not Submitted <span>'.$waiting.'</span></button><button type="button" class="mwat-filter" data-filter="done">Submitted <span>'.$submitted.'</span></button><button type="button" class="mwat-filter" data-filter="cancelled">Cancelled <span>'.$cancelled.'</span></button></div><div id="mwat-walk-list" class="mwat-list mwat-status-grid">';
        foreach($walks as $walk) {
            $entry=$states[$walk->ID]??null; $status='waiting'; $label='Not submitted'; $detail='';
            if($entry) {
                if(($entry['type']??'attendance')==='cancelled') { $status='cancelled'; $label='Cancelled'; $detail=$this->cancellation_label($entry); }
                else { $status='done'; $label='Submitted'; $detail=(int)($entry['attendees']??0).' attendees · '.(int)($entry['new_attendees']??0).' new'; }
            }
            $leader=get_user_by('id',(int)get_post_meta($walk->ID,'_mwat_leader_user',true));
            $sub=($leader?$leader->display_name:'No leader assigned').($detail?' · '.$detail:'');
            echo '<div class="mwat-row mwat-walk-row" data-status="'.$status.'" data-search="'.esc_attr(strtolower($walk->post_title.' '.$sub)).'"><div><strong>'.esc_html($walk->post_title).'</strong><small>'.esc_html($sub).'</small></div><div class="mwat-row-actions"><span class="mwat-status '.$status.'">'.$label.'</span>';if('waiting'===$status&&$leader){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_send_test_reminder"><input type="hidden" name="walk_id" value="'.(int)$walk->ID.'">';wp_nonce_field('mwat_send_test_reminder','mwat_nonce');echo '<button class="mwat-reminder-button" type="submit">Send Reminder</button></form>';}echo '</div></div>';
        }
        echo '</div><div id="mwat-no-results" class="mwat-empty" hidden>No walks match your search.</div></div>';
        echo '<script>(function(){var box=document.getElementById("mwat-walk-search"),rows=[].slice.call(document.querySelectorAll(".mwat-walk-row")),buttons=[].slice.call(document.querySelectorAll(".mwat-filter")),empty=document.getElementById("mwat-no-results"),filter="all";function draw(){var q=(box.value||"").toLowerCase().trim(),shown=0;rows.forEach(function(r){var yes=(filter==="all"||r.dataset.status===filter)&&(!q||r.dataset.search.indexOf(q)>-1);r.style.display=yes?"":"none";if(yes)shown++;});empty.hidden=shown>0;}buttons.forEach(function(b){b.addEventListener("click",function(){buttons.forEach(function(x){x.classList.remove("active")});b.classList.add("active");filter=b.dataset.filter;draw();});});box.addEventListener("input",draw);draw();})();</script>';
    }

    private function cancellation_label( $entry ) {
        $labels=array('weather'=>'Weather / rain','illness'=>'Leader illness / unavailable','location'=>'Venue / location issue','low_attendance'=>'Low / no attendance','other'=>'Other');
        $reason=$entry['cancel_reason']??'other';
        $label=$labels[$reason]??'Other';
        if('other'===$reason && !empty($entry['cancel_other'])) $label.=': '.$entry['cancel_other'];
        return $label;
    }

    private function public_leader_screen( $leader, $token ) {
        $walk=$this->leader_walk($leader->ID);
        if(!$walk){echo '<div class="mwat-form-card"><div class="mwat-empty"><strong>No walk assigned</strong><br>Please contact MWAT.</div></div>';return;}
        $today=wp_date('Y-m-d'); $entries=$this->entries(); $existing=$this->weekly_entry_index($walk->ID,$today,$entries);
        $notice=isset($_GET['mwat_status'])?sanitize_key(wp_unslash($_GET['mwat_status'])):'';
        echo '<div class="mwat-form-card mwat-leader-submit"><div class="mwat-leader-welcome"><span class="mwat-eyebrow">MWAT Walk Attendance</span><h3>Hi, '.esc_html($leader->display_name).'</h3><p><strong>'.esc_html($walk->post_title).'</strong><br>Send your weekly attendance. No login required.</p></div>';
        if('success'===$notice){echo '<div class="mwat-submit-success"><span class="mwat-success-icon">✓</span><div><h3>Thank you — attendance submitted</h3><p>Your weekly attendance for '.esc_html($walk->post_title).' has been successfully recorded. You do not need to do anything else this week.</p></div></div></div>';return;}
        if('future'===$notice){echo '<div class="mwat-form-notice mwat-notice-warning"><strong>You cannot submit a future walk.</strong><p>Please choose today or an earlier walk date.</p></div>';}
        if('duplicate'===$notice||false!==$existing){echo '<div class="mwat-submit-success"><span class="mwat-success-icon">✓</span><div><h3>This week has already been submitted</h3><p>We already have a response for '.esc_html($walk->post_title).' this week, so another one cannot be added. If something needs changing, please contact MWAT.</p></div></div></div>';return;}
        echo '<form class="mwat-form mwat-quick-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_submit_attendance"><input type="hidden" name="mwat_key" value="'.esc_attr($token).'"><input type="hidden" name="walk_id" value="'.(int)$walk->ID.'">';
        wp_nonce_field('mwat_submit_attendance','mwat_nonce');
        echo '<div class="mwat-assigned-walk"><span>Your walk</span><strong>'.esc_html($walk->post_title).'</strong></div><label class="mwat-date-field">Walk date<input name="walk_date" type="date" max="'.esc_attr($today).'" value="'.esc_attr($today).'" required></label>';
        echo '<fieldset class="mwat-took-place"><legend>Did the walk take place?</legend><label><input type="radio" name="walk_status" value="attendance" checked> <span><strong>Yes</strong><small>Enter the attendance figures</small></span></label><label><input type="radio" name="walk_status" value="cancelled"> <span><strong>No — walk cancelled</strong><small>Tell us why it was cancelled</small></span></label></fieldset>';
        echo '<div id="mwat-attendance-fields" class="mwat-number-stack"><label><span><strong>Attendees</strong></span><input name="attendees" type="number" min="0" inputmode="numeric" required></label><label><span><strong>New attendees</strong></span><input name="new_attendees" type="number" min="0" inputmode="numeric" required></label><label><span><strong>Dogs</strong></span><input name="dogs" type="number" min="0" inputmode="numeric" required></label></div><div id="mwat-cancel-fields" class="mwat-cancel-fields" hidden><label>Reason for cancellation<select name="cancel_reason"><option value="weather">Weather / rain</option><option value="illness">Leader illness / unavailable</option><option value="location">Venue / location issue</option><option value="low_attendance">Low / no attendance</option><option value="other">Other</option></select></label><label id="mwat-cancel-other" hidden>Other reason<textarea name="cancel_other" maxlength="500" rows="4" placeholder="Please tell us why the walk was cancelled"></textarea></label></div>';
        echo '<button class="mwat-primary mwat-submit-big" type="submit">Send Weekly Update</button><p class="mwat-submit-note">Your personal link stays the same each week.</p></form><script>(function(){var radios=document.querySelectorAll("input[name=walk_status]"),a=document.getElementById("mwat-attendance-fields"),c=document.getElementById("mwat-cancel-fields"),sel=document.querySelector("select[name=cancel_reason]"),other=document.getElementById("mwat-cancel-other");function draw(){var v=document.querySelector("input[name=walk_status]:checked").value;a.hidden=v==="cancelled";c.hidden=v!=="cancelled";if(v==="cancelled")reason();}function reason(){other.hidden=sel.value!=="other";}radios.forEach(function(r){r.addEventListener("change",draw)});sel.addEventListener("change",reason);draw();})();</script></div>';
    }

    private function leader_screen() {
        $walks = $this->assigned_walks();
        if ( $this->is_manager() ) $walks = $this->walks();
        $is_leader = ! $this->is_manager();
        echo '<div class="mwat-form-card'.($is_leader?' mwat-leader-submit':'').'">';
        if ( isset($_GET['mwat_status']) && 'success' === sanitize_key(wp_unslash($_GET['mwat_status'])) ) {
            echo '<div class="mwat-submit-success"><span class="mwat-success-icon">✓</span><div><h3>Weekly response sent</h3><p>Thank you. Your response has been recorded.</p></div></div>';
        }
        if ( ! $walks ) { echo '<div class="mwat-empty"><strong>No walk assigned</strong><br>Your account is set up, but a walk has not been assigned to you yet.</div></div>'; return; }
        if ( $is_leader ) {
            $user=wp_get_current_user();
            echo '<div class="mwat-leader-welcome"><span class="mwat-eyebrow">Walk Leader</span><h3>Hi, '.esc_html($user->display_name).'</h3><p>Send this week\'s attendance. It should only take a few seconds.</p></div>';
        } else {
            echo '<div class="mwat-intro"><h3>Record attendance</h3><p>Enter the figures for the walk and press submit.</p></div>';
        }
        echo '<form class="mwat-form mwat-quick-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_submit_attendance">';
        wp_nonce_field('mwat_submit_attendance','mwat_nonce');
        if ( 1 === count($walks) ) {
            $walk=reset($walks);
            echo '<input type="hidden" name="walk_id" value="'.(int)$walk->ID.'"><div class="mwat-assigned-walk"><span>Your walk</span><strong>'.esc_html($walk->post_title).'</strong></div>';
        } else {
            echo '<label>Walk location<select name="walk_id" required><option value="">Choose your walk</option>';
            foreach($walks as $walk) echo '<option value="'.(int)$walk->ID.'">'.esc_html($walk->post_title).'</option>';
            echo '</select></label>';
        }
        echo '<label class="mwat-date-field">Walk date<input name="walk_date" type="date" value="'.esc_attr(wp_date('Y-m-d')).'" required></label>';
        echo '<fieldset class="mwat-took-place"><legend>Did the walk take place?</legend><label><input type="radio" name="walk_status" value="attendance" checked> <span><strong>Yes</strong><small>Enter the attendance figures</small></span></label><label><input type="radio" name="walk_status" value="cancelled"> <span><strong>No — walk cancelled</strong><small>Tell us why it was cancelled</small></span></label></fieldset>';
        echo '<div id="mwat-attendance-fields" class="mwat-number-stack"><label><span><strong>Attendees</strong><small>Total people on the walk</small></span><input name="attendees" type="number" min="0" inputmode="numeric" placeholder="0"></label><label><span><strong>New attendees</strong><small>People joining for the first time</small></span><input name="new_attendees" type="number" min="0" inputmode="numeric" placeholder="0"></label><label><span><strong>Dogs</strong><small>Dogs that joined the walk</small></span><input name="dogs" type="number" min="0" inputmode="numeric" placeholder="0"></label></div>';
        echo '<div id="mwat-cancel-fields" class="mwat-cancel-fields" hidden><label>Reason for cancellation<select name="cancel_reason"><option value="weather">Weather / rain</option><option value="illness">Leader illness / unavailable</option><option value="location">Venue / location issue</option><option value="low_attendance">Low / no attendance</option><option value="other">Other</option></select></label><label id="mwat-cancel-other" hidden>Other reason<input type="text" name="cancel_other" maxlength="200" placeholder="Briefly tell us why"></label></div>';
        echo '<button class="mwat-primary mwat-submit-big" type="submit">Send Weekly Update</button><p class="mwat-submit-note">One response per walk, per week. Sending again for the same week will replace the earlier response.</p></form>';
        echo '<script>(function(){var radios=document.querySelectorAll("input[name=walk_status]"),a=document.getElementById("mwat-attendance-fields"),c=document.getElementById("mwat-cancel-fields"),sel=document.querySelector("select[name=cancel_reason]"),other=document.getElementById("mwat-cancel-other");function draw(){var v=document.querySelector("input[name=walk_status]:checked").value;a.hidden=v==="cancelled";c.hidden=v!=="cancelled";if(v==="cancelled")reason();}function reason(){other.hidden=sel.value!=="other";}radios.forEach(function(r){r.addEventListener("change",draw)});sel.addEventListener("change",reason);draw();})();</script></div>';
    }

    private function admin_screen() {
        if(!$this->staging_only()){echo '<div class="mwat-empty">Demo administration tools are only available on the staging site.</div>';return;}
        echo '<div class="mwat-intro"><h3>Demo Admin</h3><p>These tools are for setting up and resetting the demonstration only. They are not part of the finished Walk Leader attendance system.</p></div>';
        echo '<div class="mwat-form-notice mwat-notice-warning"><strong>Demo tools only</strong><p>Mark does not need to use anything on this page during normal use. These controls exist only so the staging demonstration can be populated with test leaders and sample attendance data.</p></div>';
        if(isset($_GET['mwat_test_status'])){$ts=sanitize_key(wp_unslash($_GET['mwat_test_status']));if('imported'===$ts)echo '<div class="mwat-success">✓ Test leaders imported: '.absint($_GET['created']??0).' created, '.absint($_GET['assigned']??0).' assigned, '.absint($_GET['skipped']??0).' skipped.</div>';if('deleted'===$ts)echo '<div class="mwat-success">✓ '.absint($_GET['deleted']??0).' imported test leader accounts deleted and their assignments removed.</div>';}
        if(isset($_GET['mwat_seed_status'])&&'done'===sanitize_key(wp_unslash($_GET['mwat_seed_status']))) echo '<div class="mwat-success">✓ Four weeks of staging attendance added for all walks.</div>';
        if(isset($_GET['mwat_demo_status'])&&'done'===sanitize_key(wp_unslash($_GET['mwat_demo_status']))) echo '<div class="mwat-success">✓ Demo week created: '.absint($_GET['submitted']??0).' submitted, '.absint($_GET['cancelled']??0).' cancelled and '.absint($_GET['waiting']??0).' not submitted.</div>';
        if(isset($_GET['mwat_current_demo_status'])&&'done'===sanitize_key(wp_unslash($_GET['mwat_current_demo_status']))) echo '<div class="mwat-success">✓ This week demo data created.</div>';
        $test_users=get_users(array('meta_key'=>'_mwat_test_leader','meta_value'=>'1'));
        echo '<div class="mwat-card mwat-test-tools"><h3>Staging test leaders</h3><p class="mwat-help">Create a fake Walk Leader for every GeoDirectory walk and automatically assign them. No real leader data is used. These accounts are tagged so they can be safely removed later.</p><div class="mwat-test-summary"><strong>'.count($test_users).'</strong> test leader account'.(count($test_users)===1?'':'s').' currently exist.</div><div class="mwat-test-actions"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_import_test_leaders">';
        wp_nonce_field('mwat_import_test_leaders','mwat_nonce');
        echo '<button class="mwat-primary" type="submit">Import Test Leaders</button></form>';
        if($test_users){echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" onsubmit="return confirm(\'Delete all imported MWAT test leaders and remove their walk assignments?\');"><input type="hidden" name="action" value="mwat_delete_test_leaders">';wp_nonce_field('mwat_delete_test_leaders','mwat_nonce');echo '<button class="mwat-secondary" type="submit">Delete Test Leaders</button></form>';}
        echo '</div><small>Test password: <strong>mwat123!!</strong> · Test emails use @mwat1.com</small></div>';
        echo '<div class="mwat-card mwat-test-tools"><h3>Staging attendance data</h3><p class="mwat-help">Fill the four completed weeks ending 6 September 2026 with realistic test attendance for every walk. Nothing is created after 6 September.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_seed_test_attendance">';wp_nonce_field('mwat_seed_test_attendance','mwat_nonce');echo '<button class="mwat-primary" type="submit">Add 4 Weeks Test Attendance</button></form></div>';
        echo '<div class="mwat-card mwat-test-tools"><h3>7–13 September demo week</h3><p class="mwat-help">Create a mixed week for demonstrating the dashboard: 15 walks not submitted, 3 cancelled and all remaining walks submitted.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_seed_demo_week">';wp_nonce_field('mwat_seed_demo_week','mwat_nonce');echo '<button class="mwat-primary" type="submit">Create Demo Week</button></form></div>';
        echo '<div class="mwat-card mwat-test-tools"><h3>This week: 14–20 September</h3><p class="mwat-help">Create the same dashboard mix for this week: 15 not submitted, 3 cancelled and all remaining walks submitted. Test submissions are dated no later than today.</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_seed_current_demo_week">';wp_nonce_field('mwat_seed_current_demo_week','mwat_nonce');echo '<button class="mwat-primary" type="submit">Create This Week Demo</button></form></div>';

    }

    private function walks_screen() {
        $users = get_users( array( 'role' => 'walk_leader', 'orderby' => 'display_name' ) );
        $walks = $this->walks();
        $assigned = 0;
        foreach ( $walks as $walk ) if ( (int) get_post_meta( $walk->ID, '_mwat_leader_user', true ) ) $assigned++;
        echo '<div class="mwat-intro"><h3>Walk Leaders</h3><p>Create leaders and connect each person to their walk.</p></div>';
        if(isset($_GET['mwat_test_status'])){$ts=sanitize_key(wp_unslash($_GET['mwat_test_status']));if('imported'===$ts)echo '<div class="mwat-success">✓ Test leaders imported: '.absint($_GET['created']??0).' created, '.absint($_GET['assigned']??0).' assigned, '.absint($_GET['skipped']??0).' skipped (no leader name or import issue).</div>';if('deleted'===$ts)echo '<div class="mwat-success">✓ '.absint($_GET['deleted']??0).' imported test leader accounts deleted and their assignments removed.</div>';}
        if ( isset($_GET['mwat_leader_status']) ) {
            $status=sanitize_key(wp_unslash($_GET['mwat_leader_status']));
            if('created'===$status) echo '<div class="mwat-success">✓ Walk Leader account created. You can now assign them to a walk.</div>';
            if('converted'===$status) echo '<div class="mwat-success">✓ Existing website account changed to Walk Leader. Their normal website access is preserved.</div>'; 
            if('assigned'===$status) echo '<div class="mwat-success">✓ Walk leader assignment saved.</div>'; if('regenerated'===$status) echo '<div class="mwat-success">✓ A new private attendance link has been generated'.(!empty($_GET['mwat_email_sent'])?' and emailed to the leader':'').'. The old link no longer works.</div>'; 
        }
        echo '<div class="mwat-stats mwat-leader-stats"><div><strong>'.count($walks).'</strong><span>Total walks</span></div><div><strong>'.$assigned.'</strong><span>Leader assigned</span></div><div><strong>'.max(0,count($walks)-$assigned).'</strong><span>Need a leader</span></div></div>';
        echo '<div class="mwat-grid mwat-leader-grid"><div>';
        echo '<div class="mwat-card"><h3>Create a leader</h3><p class="mwat-help">Add the leader and choose their walk. A secure permanent attendance link is generated and emailed automatically — no login needed.</p><form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_create_leader">';
        wp_nonce_field('mwat_create_leader','mwat_nonce');
        echo '<label>Name<input name="leader_name" type="text" required></label><label>Email address<input name="leader_email" type="email" required></label><label>Walk<select name="walk_id" required><option value="">Choose a walk</option>';foreach($walks as $walk)echo '<option value="'.(int)$walk->ID.'">'.esc_html($walk->post_title).'</option>';echo '</select></label><button class="mwat-primary" type="submit">Create Leader & Send Link</button></form></div>'; 
        echo '<div class="mwat-card mwat-spaced"><h3>Assign to a walk</h3><p class="mwat-help">A leader can be assigned to an existing walk.</p><form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_assign_leader">';
        wp_nonce_field('mwat_assign_leader','mwat_nonce');
        echo '<label>Walk<select name="walk_id" required><option value="">Choose a walk</option>';
        foreach($walks as $walk) echo '<option value="'.(int)$walk->ID.'">'.esc_html($walk->post_title).'</option>';
        echo '</select></label><label>Leader<select name="leader_user"><option value="0">No leader assigned</option>';
        foreach($users as $u) echo '<option value="'.(int)$u->ID.'">'.esc_html($u->display_name.' — '.$u->user_email).'</option>';
        echo '</select></label><button class="mwat-primary" type="submit">Save Assignment</button></form></div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head mwat-dashboard-head"><div><h3>Walk assignments</h3><p>Search by walk or leader.</p></div><input id="mwat-leader-search" class="mwat-search" type="search" placeholder="Search walks or leaders..."></div><div class="mwat-filters"><button type="button" class="mwat-leader-filter active" data-filter="unassigned">Need a leader <span>'.max(0,count($walks)-$assigned).'</span></button><button type="button" class="mwat-leader-filter" data-filter="assigned">Assigned <span>'.$assigned.'</span></button><button type="button" class="mwat-leader-filter" data-filter="all">All <span>'.count($walks).'</span></button></div><div class="mwat-list">';
        foreach($walks as $walk) {
            $leader=get_user_by('id',(int)get_post_meta($walk->ID,'_mwat_leader_user',true));
            echo '<div class="mwat-row mwat-leader-row" data-status="'.($leader?'assigned':'unassigned').'" data-search="'.esc_attr(strtolower($walk->post_title.' '.($leader?$leader->display_name.' '.$leader->user_email:''))).'"><div><strong>'.esc_html($walk->post_title).'</strong><small>'.esc_html($leader?$leader->display_name.' — '.$leader->user_email:'No leader assigned').'</small>';if($leader){$link=$this->leader_link($leader->ID);echo '<div class="mwat-leader-link"><input type="text" readonly value="'.esc_attr($link).'" onclick="this.select()"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_regenerate_leader_link"><input type="hidden" name="leader_user" value="'.(int)$leader->ID.'"><input type="hidden" name="send_email" value="1">';wp_nonce_field('mwat_regenerate_leader_link','mwat_nonce');echo '<button class="mwat-secondary" type="submit" onclick="return confirm(\'Generate a new link? The old link will immediately stop working.\')">New Link & Email</button></form></div>';}echo '</div><span class="mwat-status '.($leader?'done':'waiting').'">'.($leader?'Assigned':'Needs leader').'</span></div>';
        }
        echo '</div><div id="mwat-leader-empty" class="mwat-empty" hidden>No matching walks found.</div></div></div>';
        echo '<script>(function(){var box=document.getElementById("mwat-leader-search"),rows=[].slice.call(document.querySelectorAll(".mwat-leader-row")),buttons=[].slice.call(document.querySelectorAll(".mwat-leader-filter")),empty=document.getElementById("mwat-leader-empty"),filter="unassigned";function draw(){var q=(box.value||"").toLowerCase().trim(),n=0;rows.forEach(function(r){var show=(filter==="all"||r.dataset.status===filter)&&(!q||r.dataset.search.indexOf(q)>-1);r.style.display=show?"":"none";if(show)n++;});empty.hidden=n>0;}buttons.forEach(function(b){b.addEventListener("click",function(){buttons.forEach(function(x){x.classList.remove("active")});b.classList.add("active");filter=b.dataset.filter;draw();});});box.addEventListener("input",draw);draw();})();</script>';
    }

    private function history_filters() {
        $period=isset($_GET['mwat_period'])?sanitize_key(wp_unslash($_GET['mwat_period'])):'lastweek';
        $today=wp_date('Y-m-d'); list($this_week_start,$this_week_end)=$this->week_bounds($today);
        $last_week_start=wp_date('Y-m-d',strtotime($this_week_start.' -7 days'));
        $last_week_end=wp_date('Y-m-d',strtotime($this_week_start.' -1 day'));
        if('lastmonth'===$period){$from=wp_date('Y-m-01',strtotime('first day of last month'));$to=wp_date('Y-m-t',strtotime('last day of last month'));}
        elseif('custom'===$period){$from=isset($_GET['mwat_from'])?sanitize_text_field(wp_unslash($_GET['mwat_from'])):$last_week_start;$to=isset($_GET['mwat_to'])?sanitize_text_field(wp_unslash($_GET['mwat_to'])):$last_week_end;}
        else {$period='lastweek';$from=$last_week_start;$to=$last_week_end;}
        return array(
            'period'=>$period,
            'from'=>$from,
            'to'=>$to,
            'walk'=>isset($_GET['mwat_walk'])?absint($_GET['mwat_walk']):0,
            'status'=>isset($_GET['mwat_status_filter'])?sanitize_key(wp_unslash($_GET['mwat_status_filter'])):'all',
        );
    }

    private function filtered_entries( $entries, $filters ) {
        return array_filter($entries,function($entry) use($filters){
            $date=$entry['date']??'';
            if($filters['from']&&$date<$filters['from']) return false;
            if($filters['to']&&$date>$filters['to']) return false;
            if($filters['walk']&&(int)($entry['walk_id']??0)!==$filters['walk']) return false;
            $type=($entry['type']??'attendance')==='cancelled'?'cancelled':'submitted';
            if($filters['status']!=='all'&&$filters['status']!==$type) return false;
            return true;
        });
    }

    private function analytics_screen() {
        $entries=$this->entries(); $walks=$this->walks();
        $preset=isset($_GET['mwat_period'])?sanitize_key(wp_unslash($_GET['mwat_period'])):'week';
        $today=wp_date('Y-m-d'); list($this_week_start,$this_week_end)=$this->week_bounds($today);
        if('week'===$preset){$from=$this_week_start;$to=$today;}
        elseif('lastweek'===$preset){$from=wp_date('Y-m-d',strtotime($this_week_start.' -7 days'));$to=wp_date('Y-m-d',strtotime($this_week_start.' -1 day'));}
        elseif('lastmonth'===$preset){$from=wp_date('Y-m-01',strtotime('first day of last month'));$to=wp_date('Y-m-t',strtotime('last day of last month'));}
        elseif('custom'===$preset){$from=isset($_GET['mwat_from'])?sanitize_text_field(wp_unslash($_GET['mwat_from'])):$this_week_start;$to=isset($_GET['mwat_to'])?sanitize_text_field(wp_unslash($_GET['mwat_to'])):$today;}
        else {$preset='week';$from=$this_week_start;$to=$today;}
        $filtered=array_values(array_filter($entries,function($e)use($from,$to){$d=$e['date']??'';return $d>=$from&&$d<=$to;}));
        $att=0;$new=0;$dogs=0;$cancel=0;$submitted=0;$by_walk=array();$reasons=array();$weekly=array();
        foreach($filtered as $e){$type=$e['type']??'attendance';$wid=(int)($e['walk_id']??0);$week=wp_date('Y-m-d',strtotime(($e['date']??$today).' monday this week'));if(!isset($weekly[$week]))$weekly[$week]=array('att'=>0,'new'=>0,'responses'=>0);
            if('cancelled'===$type){$cancel++;$r=$this->cancellation_label($e);$reasons[$r]=($reasons[$r]??0)+1;}else{$submitted++;$a=(int)($e['attendees']??0);$att+=$a;$enew=(int)($e['new_attendees']??0);$new+=$enew;$weekly[$week]['new']+=$enew;$dogs+=(int)($e['dogs']??0);$by_walk[$wid]=($by_walk[$wid]??0)+$a;$weekly[$week]['att']+=$a;}$weekly[$week]['responses']++;}
        arsort($by_walk); arsort($reasons); ksort($weekly); $responses=count($filtered); $avg=$submitted?round($att/$submitted,1):0;
        echo '<div class="mwat-intro"><h3>Analytics</h3><p>See attendance trends and how walks are performing over time.</p></div>';
        $base=remove_query_arg(array('mwat_period','mwat_from','mwat_to'));echo '<div class="mwat-periods">';
        foreach(array('week'=>'This Week','lastweek'=>'Last Week','lastmonth'=>'Last Month','custom'=>'Custom') as $k=>$label)echo '<a class="'.($preset===$k?'active':'').'" href="'.esc_url(add_query_arg('mwat_period',$k,$base)).'">'.esc_html($label).'</a>';echo '</div>';
        if('custom'===$preset)echo '<form class="mwat-analytics-range" method="get"><input type="hidden" name="mwat_tab" value="analytics"><input type="hidden" name="mwat_period" value="custom"><label>From<input type="date" name="mwat_from" value="'.esc_attr($from).'"></label><label>To<input type="date" name="mwat_to" value="'.esc_attr($to).'"></label><button class="mwat-primary">Apply</button></form>';
        echo '<p class="mwat-date-range">'.esc_html(date_i18n('j M Y',strtotime($from))).' – '.esc_html(date_i18n('j M Y',strtotime($to))).'</p>';
        echo '<div class="mwat-stats mwat-analytics-stats"><div><strong>'.$att.'</strong><span>Total attendees</span></div><div><strong>'.$new.'</strong><span>New attendees</span></div><div><strong>'.$avg.'</strong><span>Average per walk</span></div><div><strong>'.$dogs.'</strong><span>Dogs</span></div><div><strong>'.$cancel.'</strong><span>Cancelled</span></div></div>';
        echo '<div class="mwat-analytics-grid"><div class="mwat-card"><div class="mwat-card-head"><div><h3>Weekly growth</h3><p>Total attendance compared with new attendees each week.</p></div></div><div class="mwat-bars mwat-growth-bars">';
        $max=1;foreach($weekly as $w)$max=max($max,$w['att']);foreach($weekly as $week=>$w){$pct=round(($w['att']/$max)*100);$newpct=round(($w['new']/$max)*100);echo '<div class="mwat-growth-row"><span>'.esc_html(date_i18n('j M',strtotime($week))).'</span><div class="mwat-growth-values"><div><small>Attended</small><div class="mwat-track"><i style="width:'.$pct.'%"></i></div><strong>'.$w['att'].'</strong></div><div><small>New</small><div class="mwat-track"><i class="mwat-new-bar" style="width:'.$newpct.'%"></i></div><strong>'.$w['new'].'</strong></div></div></div>';}if(!$weekly)echo '<div class="mwat-empty">No data in this period.</div>';echo '</div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head"><div><h3>Top walks</h3><p>By total attendance</p></div></div><div class="mwat-ranking">';$rank=0;foreach($by_walk as $wid=>$total){if(++$rank>8)break;echo '<div><span>'.$rank.'</span><strong>'.esc_html(get_the_title($wid)).'</strong><b>'.$total.'</b></div>';}if(!$by_walk)echo '<div class="mwat-empty">No attendance yet.</div>';echo '</div></div>';
        $group_months=array(); foreach($walks as $walk){$created=get_post_time('Y-m-d',false,$walk);if(!$created)continue;$month=wp_date('Y-m-01',strtotime($created));$group_months[$month]=($group_months[$month]??0)+1;}ksort($group_months);$running=0;
        echo '<div class="mwat-card mwat-groups-growth"><div class="mwat-card-head"><div><h3>Group growth</h3><p>New walk groups added to the website over time.</p></div></div><div class="mwat-bars">';foreach($group_months as $month=>$added){$running+=$added;echo '<div class="mwat-group-growth-row"><span>'.esc_html(date_i18n('M Y',strtotime($month))).'</span><div><small>+'.(int)$added.' new</small><strong>'.$running.' groups</strong></div></div>';}if(!$group_months)echo '<div class="mwat-empty">No group dates available.</div>';echo '</div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head"><div><h3>Responses</h3><p>Submitted versus cancelled</p></div></div><div class="mwat-analytics-summary"><div><strong>'.$submitted.'</strong><span>Attendance submitted</span></div><div><strong>'.$cancel.'</strong><span>Cancelled</span></div><div><strong>'.$responses.'</strong><span>Total responses</span></div></div></div>';
        echo '<div class="mwat-card"><div class="mwat-card-head"><div><h3>Cancellation reasons</h3><p>Reasons recorded by leaders</p></div></div><div class="mwat-ranking">';foreach($reasons as $reason=>$n)echo '<div><strong>'.esc_html($reason).'</strong><b>'.$n.'</b></div>';if(!$reasons)echo '<div class="mwat-empty">No cancellations in this period.</div>';echo '</div></div></div>';
    }

    private function history_screen() {
        $entries=$this->entries(); $filters=$this->history_filters(); $filtered=$this->filtered_entries($entries,$filters);
        $edit=isset($_GET['mwat_edit'])?absint($_GET['mwat_edit']):-1;
        if(isset($_GET['mwat_edit_status'])&&'saved'===sanitize_key(wp_unslash($_GET['mwat_edit_status']))) echo '<div class="mwat-success">✓ Weekly response updated.</div>';
        if(isset($_GET['mwat_seed_status'])&&'done'===sanitize_key(wp_unslash($_GET['mwat_seed_status']))) echo '<div class="mwat-success">✓ Four weeks of staging attendance added for all walks. '.absint($_GET['added']??0).' records added and '.absint($_GET['replaced']??0).' existing weekly test records replaced. No data was added after 6 September 2026.</div>';
        if(isset($_GET['mwat_demo_status'])&&'done'===sanitize_key(wp_unslash($_GET['mwat_demo_status']))) echo '<div class="mwat-success">✓ Demo week created: '.absint($_GET['submitted']??0).' submitted, '.absint($_GET['cancelled']??0).' cancelled and '.absint($_GET['waiting']??0).' not submitted.</div>';
        if(isset($_GET['mwat_current_demo_status'])&&'done'===sanitize_key(wp_unslash($_GET['mwat_current_demo_status']))) echo '<div class="mwat-success">✓ This week created: '.absint($_GET['submitted']??0).' submitted, '.absint($_GET['cancelled']??0).' cancelled and '.absint($_GET['waiting']??0).' not submitted.</div>'; 
        if($edit>=0&&isset($entries[$edit])) {
            $entry=$entries[$edit]; $cancelled=($entry['type']??'attendance')==='cancelled'; $walk=get_the_title((int)($entry['walk_id']??0));
            echo '<div class="mwat-card mwat-edit-card"><div class="mwat-card-head"><div><h3>Edit weekly response</h3><p>'.esc_html($walk).'</p></div><a class="mwat-secondary" href="'.esc_url(remove_query_arg('mwat_edit')).'">Cancel edit</a></div><form class="mwat-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="mwat_save_entry"><input type="hidden" name="entry_index" value="'.$edit.'">';
            wp_nonce_field('mwat_save_entry','mwat_nonce');
            echo '<label>Walk date<input type="date" name="walk_date" value="'.esc_attr($entry['date']??'').'" required></label><fieldset class="mwat-took-place"><legend>Did the walk take place?</legend><label><input type="radio" name="walk_status" value="attendance" '.checked(!$cancelled,true,false).'> <span><strong>Yes</strong></span></label><label><input type="radio" name="walk_status" value="cancelled" '.checked($cancelled,true,false).'> <span><strong>No — walk cancelled</strong></span></label></fieldset>';
            echo '<div id="mwat-edit-attendance" class="mwat-number-stack" '.($cancelled?'hidden':'').'><label><span><strong>Attendees</strong></span><input name="attendees" type="number" min="0" value="'.(int)($entry['attendees']??0).'"></label><label><span><strong>New attendees</strong></span><input name="new_attendees" type="number" min="0" value="'.(int)($entry['new_attendees']??0).'"></label><label><span><strong>Dogs</strong></span><input name="dogs" type="number" min="0" value="'.(int)($entry['dogs']??0).'"></label></div>';
            $reason=$entry['cancel_reason']??'weather'; echo '<div id="mwat-edit-cancel" class="mwat-cancel-fields" '.(!$cancelled?'hidden':'').'><label>Reason<select name="cancel_reason">';
            foreach(array('weather'=>'Weather / rain','illness'=>'Leader illness / unavailable','location'=>'Venue / location issue','low_attendance'=>'Low / no attendance','other'=>'Other') as $key=>$label) echo '<option value="'.$key.'" '.selected($reason,$key,false).'>'.$label.'</option>';
            echo '</select></label><label id="mwat-edit-other" '.('other'!==$reason?'hidden':'').'>Other reason<input type="text" name="cancel_other" maxlength="200" value="'.esc_attr($entry['cancel_other']??'').'"></label></div><button class="mwat-primary" type="submit">Save Changes</button></form></div><script>(function(){var rs=document.querySelectorAll(".mwat-edit-card input[name=walk_status]"),a=document.getElementById("mwat-edit-attendance"),c=document.getElementById("mwat-edit-cancel"),s=document.querySelector(".mwat-edit-card select[name=cancel_reason]"),o=document.getElementById("mwat-edit-other");function d(){var v=document.querySelector(".mwat-edit-card input[name=walk_status]:checked").value;a.hidden=v==="cancelled";c.hidden=v!=="cancelled";r()}function r(){o.hidden=s.value!=="other"}rs.forEach(function(x){x.addEventListener("change",d)});s.addEventListener("change",r)})();</script>';
        }
        $attendees=0;$new=0;$dogs=0;$cancelled_count=0; foreach($filtered as $entry){if(($entry['type']??'attendance')==='cancelled')$cancelled_count++;else{$attendees+=(int)($entry['attendees']??0);$new+=(int)($entry['new_attendees']??0);$dogs+=(int)($entry['dogs']??0);}}
        echo '<div class="mwat-intro"><h3>History & reports</h3><p>Review previous attendance, make corrections and export records.</p></div>';
        $hbase=remove_query_arg(array('mwat_period','mwat_from','mwat_to','mwat_walk','mwat_status_filter','mwat_edit'));echo '<div class="mwat-periods">';
        foreach(array('lastweek'=>'Last Week','lastmonth'=>'Last Month','custom'=>'Custom') as $k=>$label)echo '<a class="'.($filters['period']===$k?'active':'').'" href="'.esc_url(add_query_arg(array('mwat_tab'=>'history','mwat_period'=>$k),$hbase)).'">'.esc_html($label).'</a>';echo '</div>';
        echo '<p class="mwat-date-range">'.esc_html(date_i18n('j M Y',strtotime($filters['from']))).' – '.esc_html(date_i18n('j M Y',strtotime($filters['to']))).'</p>';
        echo '<form class="mwat-card mwat-history-filters'.('custom'===$filters['period']?'':' mwat-history-compact').'" method="get"><input type="hidden" name="mwat_tab" value="history"><input type="hidden" name="mwat_period" value="'.esc_attr($filters['period']).'">'.('custom'===$filters['period']?'<label>From<input type="date" name="mwat_from" value="'.esc_attr($filters['from']).'"></label><label>To<input type="date" name="mwat_to" value="'.esc_attr($filters['to']).'"></label>':'').'<label>Walk<select name="mwat_walk"><option value="0">All walks</option>';
        foreach($this->walks() as $walk) echo '<option value="'.$walk->ID.'" '.selected($filters['walk'],$walk->ID,false).'>'.esc_html($walk->post_title).'</option>';
        echo '</select></label><label>Status<select name="mwat_status_filter"><option value="all">All</option><option value="submitted" '.selected($filters['status'],'submitted',false).'>Submitted</option><option value="cancelled" '.selected($filters['status'],'cancelled',false).'>Cancelled</option></select></label><div class="mwat-filter-actions"><button class="mwat-primary" type="submit">Apply Filters</button><a class="mwat-secondary" href="'.esc_url(add_query_arg(array('mwat_tab'=>'history','mwat_period'=>'lastweek'),remove_query_arg(array('mwat_from','mwat_to','mwat_walk','mwat_status_filter','mwat_edit')))).'">Clear</a></div></form>';
        echo '<div class="mwat-stats mwat-report-stats"><div><strong>'.count($filtered).'</strong><span>Responses</span></div><div><strong>'.$attendees.'</strong><span>Attendees</span></div><div><strong>'.$new.'</strong><span>New attendees</span></div><div><strong>'.$dogs.'</strong><span>Dogs</span></div><div><strong>'.$cancelled_count.'</strong><span>Cancelled</span></div></div>';
        $export=wp_nonce_url(add_query_arg(array('action'=>'mwat_export_csv','mwat_from'=>$filters['from'],'mwat_to'=>$filters['to'],'mwat_walk'=>$filters['walk'],'mwat_status_filter'=>$filters['status']),admin_url('admin-post.php')),'mwat_export_csv');
        echo '<div class="mwat-card"><div class="mwat-card-head"><div><h3>Attendance history</h3><p>'.count($filtered).' matching response'.(count($filtered)===1?'':'s').'</p></div><a class="mwat-secondary" href="'.esc_url($export).'">Download CSV</a></div><div class="mwat-table-wrap"><table class="mwat-table"><thead><tr><th>Date</th><th>Walk</th><th>Status</th><th>Attendees</th><th>New</th><th>Dogs</th><th>Reason</th><th>Submitted by</th><th></th></tr></thead><tbody>';
        foreach(array_reverse($filtered,true) as $index=>$entry){$walk=!empty($entry['walk_id'])?get_the_title((int)$entry['walk_id']):($entry['location']??'—');$user=!empty($entry['user_id'])?get_user_by('id',(int)$entry['user_id']):false;$can=($entry['type']??'attendance')==='cancelled';echo '<tr><td>'.esc_html($entry['date']??'').'</td><td>'.esc_html($walk).'</td><td>'.($can?'Cancelled':'Submitted').'</td><td>'.($can?'—':(int)($entry['attendees']??0)).'</td><td>'.($can?'—':(int)($entry['new_attendees']??0)).'</td><td>'.($can?'—':(int)($entry['dogs']??0)).'</td><td>'.esc_html($can?$this->cancellation_label($entry):'—').'</td><td>'.esc_html($user?$user->display_name:'Legacy entry').(!empty($entry['edited_at'])?'<small class="mwat-edited">Edited</small>':'').'</td><td><a class="mwat-edit-link" href="'.esc_url(add_query_arg(array('mwat_tab'=>'history','mwat_edit'=>$index))).'">Edit</a></td></tr>';}
        if(!$filtered) echo '<tr><td colspan="9">No responses match these filters.</td></tr>'; echo '</tbody></table></div></div>';
    }

    public function export_csv() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        check_admin_referer('mwat_export_csv');
        $filters=$this->history_filters(); $entries=$this->filtered_entries($this->entries(),$filters);
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="mwat-attendance-'.wp_date('Y-m-d').'.csv"');
        $out=fopen('php://output','w'); fputcsv($out,array('Walk Date','Walk','Status','Attendees','New Attendees','Dogs','Cancellation Reason','Submitted By','Submitted At','Edited At'));
        foreach($entries as $entry){$can=($entry['type']??'attendance')==='cancelled';$user=!empty($entry['user_id'])?get_user_by('id',(int)$entry['user_id']):false;fputcsv($out,array($entry['date']??'',!empty($entry['walk_id'])?get_the_title((int)$entry['walk_id']):($entry['location']??''),$can?'Cancelled':'Submitted',$can?'':(int)($entry['attendees']??0),$can?'':(int)($entry['new_attendees']??0),$can?'':(int)($entry['dogs']??0),$can?$this->cancellation_label($entry):'',$user?$user->display_name:'Legacy entry',$entry['submitted_at']??'',$entry['edited_at']??''));}
        fclose($out); exit;
    }

    public function submit_attendance() {
        $token=isset($_POST['mwat_key'])?sanitize_text_field(wp_unslash($_POST['mwat_key'])):'';
        $public_leader=$token?$this->leader_by_token($token):false;
        if(!$public_leader&&!is_user_logged_in()) wp_die('This attendance link is invalid.');
        check_admin_referer('mwat_submit_attendance','mwat_nonce');
        $walk_id=isset($_POST['walk_id'])?absint($_POST['walk_id']):0;$date=isset($_POST['walk_date'])?sanitize_text_field(wp_unslash($_POST['walk_date'])):'';$type=isset($_POST['walk_status'])?sanitize_key(wp_unslash($_POST['walk_status'])):'attendance';
        if($public_leader){$walk=$this->leader_walk($public_leader->ID);$allowed=$walk?array($walk->ID):array();$user_id=$public_leader->ID;}else{$allowed=wp_list_pluck($this->assigned_walks(),'ID');$user_id=get_current_user_id();}
        if(!$walk_id||!in_array($walk_id,$allowed,true)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!in_array($type,array('attendance','cancelled'),true)) wp_die('Please check the weekly update.');
        if($date>wp_date('Y-m-d')){if($public_leader){wp_safe_redirect(add_query_arg(array('mwat_key'=>$token,'mwat_status'=>'future'),$this->attendance_page_url()));exit;}wp_die('Future walk dates cannot be submitted.');}
        $entries=$this->entries();$existing=$this->weekly_entry_index($walk_id,$date,$entries);if(false!==$existing){if($public_leader){wp_safe_redirect(add_query_arg(array('mwat_key'=>$token,'mwat_status'=>'duplicate'),$this->attendance_page_url()));exit;}wp_die('A response has already been submitted for this walk and week. Please contact MWAT if it needs correcting.');}
        $entry=array('walk_id'=>$walk_id,'date'=>$date,'type'=>$type,'user_id'=>$user_id,'submitted_at'=>current_time('mysql'));
        if('cancelled'===$type){$reason=isset($_POST['cancel_reason'])?sanitize_key(wp_unslash($_POST['cancel_reason'])):'';$valid=array('weather','illness','location','low_attendance','other');if(!in_array($reason,$valid,true))wp_die('Please choose a cancellation reason.');$other=isset($_POST['cancel_other'])?sanitize_text_field(wp_unslash($_POST['cancel_other'])):'';if('other'===$reason&&!$other)wp_die('Please enter the cancellation reason.');$entry['cancel_reason']=$reason;$entry['cancel_other']=$other;$entry['attendees']=0;$entry['new_attendees']=0;$entry['dogs']=0;}else{$att=isset($_POST['attendees'])?absint($_POST['attendees']):0;$new=isset($_POST['new_attendees'])?absint($_POST['new_attendees']):0;$dogs=isset($_POST['dogs'])?absint($_POST['dogs']):0;if($new>$att)wp_die('New attendees cannot be higher than total attendees.');$entry['attendees']=$att;$entry['new_attendees']=$new;$entry['dogs']=$dogs;}
        $entries[]=$entry;update_option('mwat_attendance_entries',array_values($entries),false);
        $return=$public_leader?add_query_arg(array('mwat_key'=>$token,'mwat_status'=>'success'),$this->attendance_page_url()):add_query_arg(array('mwat_tab'=>'submit','mwat_status'=>'success'),wp_get_referer()?:home_url('/'));wp_safe_redirect($return);exit;
    }

    public function send_test_reminder() {
        if(!$this->is_manager()||!$this->staging_only())wp_die('Test reminders are only available to managers on staging.');
        check_admin_referer('mwat_send_test_reminder','mwat_nonce');
        $walk_id=isset($_POST['walk_id'])?absint($_POST['walk_id']):0;$walk=get_post($walk_id);if(!$walk||'gd_place'!==$walk->post_type)wp_die('Walk not found.');
        $leader=get_user_by('id',(int)get_post_meta($walk_id,'_mwat_leader_user',true));if(!$leader)wp_die('No leader is assigned to this walk.');
        list($start,$end)=$this->week_bounds();if(false!==$this->weekly_entry_index($walk_id,wp_date('Y-m-d'),$this->entries()))wp_die('This walk has already submitted for the current week.');
        $link=$this->leader_link($leader->ID);
        $subject='Reminder: '.$walk->post_title.' weekly attendance';
        $message='<p>Hi '.esc_html($leader->display_name).',</p><p>Just a reminder that we have not yet received this week\'s attendance for <strong>'.esc_html($walk->post_title).'</strong>.</p><p><a href="'.esc_url($link).'" style="display:inline-block;background:#116b45;color:#ffffff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:8px;">Submit Weekly Attendance</a></p><p>If the button does not work, copy and paste this link into your browser:<br><span style="font-size:12px;color:#68756e;word-break:break-all;">'.esc_html($link).'</span></p><p>This is the same personal link you can use each week. If the walk was cancelled, please still submit the form and choose the cancellation reason.</p><p>Thank you,<br>Men Walking &amp; Talking</p>';
        $headers=array('Content-Type: text/html; charset=UTF-8');
        // STAGING SAFETY: all manual reminder tests are redirected to Ben, never to the fake/leader email.
        $sent=wp_mail('ben_owen@msn.com',$subject,$message,$headers);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'dashboard','mwat_reminder_status'=>$sent?'sent':'failed'),wp_get_referer()?:home_url('/')));exit;
    }

    public function create_leader() {
        if(!$this->is_manager()) wp_die('Not permitted.');check_admin_referer('mwat_create_leader','mwat_nonce');
        $name=isset($_POST['leader_name'])?sanitize_text_field(wp_unslash($_POST['leader_name'])):'';$email=isset($_POST['leader_email'])?sanitize_email(wp_unslash($_POST['leader_email'])):'';$walk_id=isset($_POST['walk_id'])?absint($_POST['walk_id']):0;
        if(!$name||!is_email($email)||!$walk_id||'gd_place'!==get_post_type($walk_id))wp_die('Please enter a valid name, email and walk.');
        $uid=email_exists($email);
        if($uid){$user=new WP_User($uid);$user->set_role('walk_leader');$user->add_cap('mwat_submit_attendance');$status='converted';}else{$base=sanitize_user(strtolower(str_replace(' ','',$name)),true)?:'walkleader';$login=$base;$i=1;while(username_exists($login)){$login=$base.$i;$i++;}$uid=wp_create_user($login,wp_generate_password(24,true,true),$email);if(is_wp_error($uid))wp_die(esc_html($uid->get_error_message()));wp_update_user(array('ID'=>$uid,'display_name'=>$name,'first_name'=>$name,'role'=>'walk_leader'));$status='created';}
        $this->leader_token($uid,true);update_post_meta($walk_id,'_mwat_leader_user',$uid);$sent=$this->send_leader_welcome($uid,$walk_id);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'walks','mwat_leader_status'=>$status,'mwat_email_sent'=>$sent?1:0),wp_get_referer()?:home_url('/')));exit;
    }

    public function regenerate_leader_link() {
        if(!$this->is_manager())wp_die('Not permitted.');check_admin_referer('mwat_regenerate_leader_link','mwat_nonce');$uid=isset($_POST['leader_user'])?absint($_POST['leader_user']):0;$walk=$this->leader_walk($uid);if(!$uid||!$walk)wp_die('Leader assignment not found.');$this->leader_token($uid,true);$sent=!empty($_POST['send_email'])?$this->send_leader_welcome($uid,$walk->ID):false;wp_safe_redirect(add_query_arg(array('mwat_tab'=>'walks','mwat_leader_status'=>'regenerated','mwat_email_sent'=>$sent?1:0),wp_get_referer()?:home_url('/')));exit;
    }

    public function assign_leader() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        check_admin_referer('mwat_assign_leader','mwat_nonce');
        $walk_id=isset($_POST['walk_id'])?absint($_POST['walk_id']):0;
        $leader_id=isset($_POST['leader_user'])?absint($_POST['leader_user']):0;
        if(!$walk_id || 'gd_place' !== get_post_type($walk_id)) wp_die('Please choose a valid GeoDirectory walk.');
        if($leader_id) {
            $leader=get_user_by('id',$leader_id);
            if(!$leader || !in_array('walk_leader',(array)$leader->roles,true)) wp_die('Please choose a valid Walk Leader.');
        }
        update_post_meta($walk_id,'_mwat_leader_user',$leader_id);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'walks','mwat_leader_status'=>'assigned'),wp_get_referer()?:home_url('/'))); exit;
    }

    public function save_entry() {
        if(!$this->is_manager()) wp_die('Not permitted.');
        check_admin_referer('mwat_save_entry','mwat_nonce');
        $index=isset($_POST['entry_index'])?absint($_POST['entry_index']):-1;
        $entries=$this->entries();
        if(!isset($entries[$index])) wp_die('This weekly response could not be found.');
        $old=$entries[$index];
        $date=isset($_POST['walk_date'])?sanitize_text_field(wp_unslash($_POST['walk_date'])):'';
        $type=isset($_POST['walk_status'])?sanitize_key(wp_unslash($_POST['walk_status'])):'attendance';
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||!in_array($type,array('attendance','cancelled'),true)) wp_die('Please check the response details.');
        $entry=$old; $entry['date']=$date; $entry['type']=$type; $entry['edited_by']=get_current_user_id(); $entry['edited_at']=current_time('mysql');
        if('cancelled'===$type){
            $reason=isset($_POST['cancel_reason'])?sanitize_key(wp_unslash($_POST['cancel_reason'])):'';
            if(!in_array($reason,array('weather','illness','location','low_attendance','other'),true)) wp_die('Please choose a cancellation reason.');
            $other=isset($_POST['cancel_other'])?sanitize_text_field(wp_unslash($_POST['cancel_other'])):'';
            if('other'===$reason&&!$other) wp_die('Please enter the cancellation reason.');
            $entry['cancel_reason']=$reason; $entry['cancel_other']=$other; $entry['attendees']=0; $entry['new_attendees']=0; $entry['dogs']=0;
        } else {
            $entry['attendees']=isset($_POST['attendees'])?absint($_POST['attendees']):0;
            $entry['new_attendees']=isset($_POST['new_attendees'])?absint($_POST['new_attendees']):0;
            $entry['dogs']=isset($_POST['dogs'])?absint($_POST['dogs']):0;
            if($entry['new_attendees']>$entry['attendees']) wp_die('New attendees cannot be higher than total attendees.');
            unset($entry['cancel_reason'],$entry['cancel_other']);
        }
        $entries[$index]=$entry;
        update_option('mwat_attendance_entries',array_values($entries),false);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'history','mwat_edit_status'=>'saved'),remove_query_arg(array('mwat_edit'),wp_get_referer()?:home_url('/')))); exit;
    }
    private function staging_only() {
        $home=home_url('/');
        return false!==strpos($home,'/stagging/');
    }

    private function test_leader_email( $name, $walk_title, $walk_id ) {
        $name=sanitize_title($name); $walk=sanitize_title(preg_replace('/\s+Walk$/i','',$walk_title));
        $name=str_replace('-','',$name); $walk=str_replace('-','',$walk);
        if(!$name)$name='leader'; if(!$walk)$walk='walk'.$walk_id;
        $base=$name.'.'.$walk; $email=$base.'@mwat1.com'; $n=2;
        while(($uid=email_exists($email)) && (int)get_user_meta($uid,'_mwat_test_walk_id',true)!==(int)$walk_id){$email=$base.$n.'@mwat1.com';$n++;}
        return $email;
    }

    public function import_test_leaders() {
        if(!$this->is_manager()||!$this->staging_only()) wp_die('This test import is only available to managers on staging.');
        check_admin_referer('mwat_import_test_leaders','mwat_nonce');
        $walks=$this->walks(); $created=0;$assigned=0;$skipped=0;
        $fake_names=array('James','David','Tom','Chris','Dan','Matt','Paul','Steve','Rob','Andy','Ben','Sam','Mark','Adam','Luke','Jack','Simon','Lee','Alex','Joe');
        foreach($walks as $i=>$walk){
            $first=$fake_names[$i%count($fake_names)];
            $place=trim(preg_replace('/\\s+(Walk|Group)(?:\\s+-.*)?$/i','',$walk->post_title));
            if(!$place)$place='Walk '.$walk->ID;
            $name=$first.' '.$place;
            $email=$this->test_leader_email($first,$walk->post_title,$walk->ID); $uid=email_exists($email);
            if(!$uid){
                $base=sanitize_user(strtolower($first.'_'.$walk->post_name),true);if(!$base)$base='mwatleader_'.$walk->ID;$login=$base;$n=2;while(username_exists($login)){$login=$base.$n;$n++;}
                $uid=wp_create_user($login,'mwat123!!',$email);if(is_wp_error($uid)){$skipped++;continue;}
                wp_update_user(array('ID'=>$uid,'display_name'=>$name,'first_name'=>$first,'last_name'=>$place,'role'=>'walk_leader'));$created++;
            } else {
                $user=new WP_User($uid);$user->set_role('walk_leader');$user->add_cap('mwat_submit_attendance');
            }
            update_user_meta($uid,'_mwat_test_leader','1');update_user_meta($uid,'_mwat_test_walk_id',$walk->ID);update_post_meta($walk->ID,'_mwat_leader_user',$uid);$assigned++;
        }
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'admin','mwat_test_status'=>'imported','created'=>$created,'assigned'=>$assigned,'skipped'=>$skipped),wp_get_referer()?:home_url('/')));exit;
    }

    public function delete_test_leaders() {
        if(!$this->is_manager()||!$this->staging_only()) wp_die('This cleanup is only available to managers on staging.');
        check_admin_referer('mwat_delete_test_leaders','mwat_nonce');require_once ABSPATH.'wp-admin/includes/user.php';$users=get_users(array('meta_key'=>'_mwat_test_leader','meta_value'=>'1'));$deleted=0;
        foreach($users as $user){$walk_id=(int)get_user_meta($user->ID,'_mwat_test_walk_id',true);if($walk_id&&(int)get_post_meta($walk_id,'_mwat_leader_user',true)===$user->ID)delete_post_meta($walk_id,'_mwat_leader_user');if(wp_delete_user($user->ID))$deleted++;}
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'admin','mwat_test_status'=>'deleted','deleted'=>$deleted),wp_get_referer()?:home_url('/')));exit;
    }

    public function seed_test_attendance() {
        if(!$this->is_manager()||!$this->staging_only()) wp_die('This test data tool is only available to managers on staging.');
        check_admin_referer('mwat_seed_test_attendance','mwat_nonce');
        $walks=$this->walks(); $entries=$this->entries(); $added=0; $replaced=0;
        $weeks=array('2026-08-10','2026-08-17','2026-08-24','2026-08-31');
        foreach($walks as $wi=>$walk){
            $leader=(int)get_post_meta($walk->ID,'_mwat_leader_user',true);
            foreach($weeks as $week_start){
                $seed=abs(crc32($walk->ID.'|'.$week_start));
                $date=wp_date('Y-m-d',strtotime($week_start.' +'.($seed%7).' days'));
                $attendees=4+($seed%18); $new=(int)(($seed>>3)%min(5,$attendees+1)); $dogs=(int)(($seed>>6)%5);
                $entry=array('walk_id'=>$walk->ID,'date'=>$date,'type'=>'attendance','user_id'=>$leader,'submitted_at'=>$date.' '.sprintf('%02d:%02d:00',18+($seed%3),($seed%12)*5),'attendees'=>$attendees,'new_attendees'=>$new,'dogs'=>$dogs,'_mwat_test_data'=>1);
                $existing=$this->weekly_entry_index($walk->ID,$date,$entries);
                if(false!==$existing){$entries[$existing]=$entry;$replaced++;}else{$entries[]=$entry;$added++;}
            }
        }
        update_option('mwat_attendance_entries',array_values($entries),false);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'admin','mwat_seed_status'=>'done','added'=>$added,'replaced'=>$replaced),wp_get_referer()?:home_url('/')));exit;
    }

    public function seed_demo_week() {
        if(!$this->is_manager()||!$this->staging_only()) wp_die('This demo data tool is only available to managers on staging.');
        check_admin_referer('mwat_seed_demo_week','mwat_nonce');
        $walks=$this->walks(); $entries=$this->entries(); $week_start='2026-09-07'; $week_end='2026-09-13';
        $entries=array_values(array_filter($entries,function($entry)use($week_start,$week_end){$d=$entry['date']??'';return $d<$week_start||$d>$week_end;}));
        usort($walks,function($a,$b){return strcmp(md5('mwat-demo-'.$a->ID),md5('mwat-demo-'.$b->ID));});
        $not_submitted=array_slice($walks,0,min(15,count($walks))); $remaining=array_slice($walks,count($not_submitted)); $cancelled=array_slice($remaining,0,min(3,count($remaining))); $submitted=array_slice($remaining,count($cancelled));
        foreach($cancelled as $i=>$walk){$seed=abs(crc32('cancel|'.$walk->ID));$date=wp_date('Y-m-d',strtotime($week_start.' +'.($seed%7).' days'));$reasons=array('weather','illness','location');$entries[]=array('walk_id'=>$walk->ID,'date'=>$date,'type'=>'cancelled','user_id'=>(int)get_post_meta($walk->ID,'_mwat_leader_user',true),'submitted_at'=>$date.' 18:30:00','attendees'=>0,'new_attendees'=>0,'dogs'=>0,'cancel_reason'=>$reasons[$i%3],'cancel_other'=>'','_mwat_test_data'=>1);}
        foreach($submitted as $walk){$seed=abs(crc32('submit|'.$walk->ID));$date=wp_date('Y-m-d',strtotime($week_start.' +'.($seed%7).' days'));$att=4+($seed%18);$entries[]=array('walk_id'=>$walk->ID,'date'=>$date,'type'=>'attendance','user_id'=>(int)get_post_meta($walk->ID,'_mwat_leader_user',true),'submitted_at'=>$date.' '.sprintf('%02d:%02d:00',18+($seed%3),($seed%12)*5),'attendees'=>$att,'new_attendees'=>(int)(($seed>>3)%min(5,$att+1)),'dogs'=>(int)(($seed>>6)%5),'_mwat_test_data'=>1);}
        update_option('mwat_attendance_entries',$entries,false);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'admin','mwat_demo_status'=>'done','submitted'=>count($submitted),'cancelled'=>count($cancelled),'waiting'=>count($not_submitted)),wp_get_referer()?:home_url('/')));exit;
    }

    public function seed_current_demo_week() {
        if(!$this->is_manager()||!$this->staging_only()) wp_die('This demo data tool is only available to managers on staging.');
        check_admin_referer('mwat_seed_current_demo_week','mwat_nonce');
        $walks=$this->walks(); $entries=$this->entries(); $week_start='2026-09-14'; $today='2026-09-18'; $week_end='2026-09-20';
        $entries=array_values(array_filter($entries,function($entry)use($week_start,$week_end){$d=$entry['date']??'';return $d<$week_start||$d>$week_end;}));
        usort($walks,function($a,$b){return strcmp(md5('mwat-current-demo-'.$a->ID),md5('mwat-current-demo-'.$b->ID));});
        $not_submitted=array_slice($walks,0,min(15,count($walks))); $remaining=array_slice($walks,count($not_submitted)); $cancelled=array_slice($remaining,0,min(3,count($remaining))); $submitted=array_slice($remaining,count($cancelled));
        $days=5;
        foreach($cancelled as $i=>$walk){$seed=abs(crc32('current-cancel|'.$walk->ID));$date=wp_date('Y-m-d',strtotime($week_start.' +'.($seed%$days).' days'));$reasons=array('weather','illness','location');$entries[]=array('walk_id'=>$walk->ID,'date'=>$date,'type'=>'cancelled','user_id'=>(int)get_post_meta($walk->ID,'_mwat_leader_user',true),'submitted_at'=>$date.' 18:30:00','attendees'=>0,'new_attendees'=>0,'dogs'=>0,'cancel_reason'=>$reasons[$i%3],'cancel_other'=>'','_mwat_test_data'=>1);}
        foreach($submitted as $walk){$seed=abs(crc32('current-submit|'.$walk->ID));$date=wp_date('Y-m-d',strtotime($week_start.' +'.($seed%$days).' days'));$att=4+($seed%18);$entries[]=array('walk_id'=>$walk->ID,'date'=>$date,'type'=>'attendance','user_id'=>(int)get_post_meta($walk->ID,'_mwat_leader_user',true),'submitted_at'=>$date.' '.sprintf('%02d:%02d:00',18+($seed%3),($seed%12)*5),'attendees'=>$att,'new_attendees'=>(int)(($seed>>3)%min(5,$att+1)),'dogs'=>(int)(($seed>>6)%5),'_mwat_test_data'=>1);}
        update_option('mwat_attendance_entries',$entries,false);
        wp_safe_redirect(add_query_arg(array('mwat_tab'=>'admin','mwat_current_demo_status'=>'done','submitted'=>count($submitted),'cancelled'=>count($cancelled),'waiting'=>count($not_submitted)),wp_get_referer()?:home_url('/')));exit;
    }

}
