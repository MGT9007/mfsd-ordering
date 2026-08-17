<?php
/**
 * Plugin Name: MFSD Ordering Utility
 * Description: Shared course ordering, task sequencing and student progress utility for all MFSD plugins.
 * Version:     1.4.0
 * Author:      s47d
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MFSD_ORDERING_VERSION', '1.4.0' );

// ─────────────────────────────────────────────
// ACTIVATION & DB VERSIONING
// ─────────────────────────────────────────────

register_activation_hook( __FILE__, 'mfsd_ordering_activate' );

function mfsd_ordering_activate() {
    mfsd_install_ordering_tables();
}

// Re-run on update if DB version has changed
add_action( 'plugins_loaded', function () {
    if ( get_option( 'mfsd_ordering_db_version' ) !== MFSD_ORDERING_VERSION ) {
        mfsd_install_ordering_tables();
    }
} );

// ─────────────────────────────────────────────
// TABLE INSTALLATION
// ─────────────────────────────────────────────

function mfsd_install_ordering_tables() {
    global $wpdb;
    $c = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Courses
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mfsd_courses (
        id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        course_name   VARCHAR(255)     NOT NULL,
        course_slug   VARCHAR(100)     NOT NULL,
        active        TINYINT(1)       NOT NULL DEFAULT 1,
        created_at    DATETIME         DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_course_slug (course_slug)
    ) $c;" );

    // Task ordering — sequence_order is the single universal sort key across all weeks
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mfsd_task_order (
        id             INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        course_id      INT UNSIGNED     NOT NULL,
        week           TINYINT UNSIGNED NOT NULL DEFAULT 1,
        task_no        TINYINT UNSIGNED NOT NULL DEFAULT 1,
        sequence_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        task_slug      VARCHAR(100)     NOT NULL,
        display_name   VARCHAR(255)     NOT NULL,
        active         TINYINT(1)       NOT NULL DEFAULT 1,
        badge_slug             VARCHAR(50)        NULL,
        badge_image            VARCHAR(500)       NULL,
        coin_value             SMALLINT UNSIGNED  NOT NULL DEFAULT 10,
        is_rag                 TINYINT(1)         NOT NULL DEFAULT 0,
        counts_for_week_badge  TINYINT(1)         NOT NULL DEFAULT 1,
        INDEX idx_course_seq  (course_id, sequence_order),
        INDEX idx_task_slug   (task_slug)
    ) $c;" );

    // Week titles per course — replaces the hardcoded WEEK_CONFIG labels in mfsd-quest-log
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mfsd_course_weeks (
        id          INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        course_id   INT UNSIGNED     NOT NULL,
        week        TINYINT UNSIGNED NOT NULL,
        title       VARCHAR(255)     NOT NULL,
        UNIQUE KEY uq_course_week (course_id, week)
    ) $c;" );

    // Per-student task progress — the single source of truth for the gate checks
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mfsd_task_progress (
        id             INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        student_id     BIGINT UNSIGNED  NOT NULL,
        course_id      INT UNSIGNED     NOT NULL,
        task_slug      VARCHAR(100)     NOT NULL,
        status         ENUM('available','in_progress','completed') NOT NULL DEFAULT 'available',
        started_date   DATETIME         NULL,
        completed_date DATETIME         NULL,
        UNIQUE KEY uq_progress        (student_id, course_id, task_slug),
        INDEX  idx_student_course     (student_id, course_id)
    ) $c;" );

    // Enrolments — reserved for Parent Portal / Student Portal queries.
    // Not used for access control (ProfilePress handles that).
    dbDelta( "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}mfsd_enrolments (
        id            INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
        student_id    BIGINT UNSIGNED  NOT NULL,
        course_id     INT UNSIGNED     NOT NULL,
        enrolled_date DATETIME         DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_enrolment (student_id, course_id)
    ) $c;" );

    update_option( 'mfsd_ordering_db_version', MFSD_ORDERING_VERSION );
}

// ─────────────────────────────────────────────
// INTERNAL HELPERS
// ─────────────────────────────────────────────

/**
 * Fetch the ordering row for a given task slug.
 *
 * @param  string       $task_slug
 * @return object|null
 */
function mfsd_get_task_order_row( $task_slug ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mfsd_task_order
         WHERE task_slug = %s AND active = 1
         LIMIT 1",
        $task_slug
    ) );
}

/**
 * Fetch the progress row for a student + task.
 *
 * @param  int          $student_id
 * @param  string       $task_slug
 * @return object|null
 */
function mfsd_get_progress_row( $student_id, $task_slug ) {
    global $wpdb;
    return $wpdb->get_row( $wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}mfsd_task_progress
         WHERE student_id = %d AND task_slug = %s
         LIMIT 1",
        $student_id,
        $task_slug
    ) );
}

// ─────────────────────────────────────────────
// PUBLIC API — used by every MFSD plugin
// ─────────────────────────────────────────────

/**
 * Get the current status of a task for a student.
 *
 * Return values:
 *   'not_configured' — task_slug not found in ordering table (fail-open)
 *   'locked'         — one or more prerequisites are not yet complete
 *   'available'      — prerequisites met, not yet started
 *   'in_progress'    — started but not complete
 *   'completed'      — fully complete
 *
 * @param  int    $student_id
 * @param  string $task_slug
 * @return string
 */
function mfsd_get_task_status( $student_id, $task_slug ) {
    global $wpdb;

    $task = mfsd_get_task_order_row( $task_slug );
    if ( ! $task ) return 'not_configured';

    $progress = mfsd_get_progress_row( $student_id, $task_slug );

    if ( $progress ) {
        if ( $progress->status === 'completed'   ) return 'completed';
        if ( $progress->status === 'in_progress' ) return 'in_progress';
    }

    // Check prerequisites: every task with a lower sequence_order in the
    // same course must have a 'completed' progress record for this student.
    if ( $task->sequence_order > 1 ) {
        $incomplete = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
             FROM   {$wpdb->prefix}mfsd_task_order  t
             LEFT   JOIN {$wpdb->prefix}mfsd_task_progress p
                    ON  p.task_slug  = t.task_slug
                    AND p.student_id = %d
             WHERE  t.course_id      = %d
               AND  t.sequence_order < %d
               AND  t.active         = 1
               AND  (p.status IS NULL OR p.status != 'completed')",
            $student_id,
            $task->course_id,
            $task->sequence_order
        ) );

        if ( $incomplete > 0 ) return 'locked';
    }

    return 'available';
}

/**
 * Set a task status for a student.
 * Only 'in_progress' and 'completed' may be written by plugins.
 *
 * @param  int    $student_id
 * @param  string $task_slug
 * @param  string $status     'in_progress' | 'completed'
 * @return bool
 */
function mfsd_set_task_status( $student_id, $task_slug, $status ) {
    global $wpdb;

    if ( ! in_array( $status, [ 'in_progress', 'completed' ], true ) ) return false;

    $task = mfsd_get_task_order_row( $task_slug );
    if ( ! $task ) return false;

    $existing = mfsd_get_progress_row( $student_id, $task_slug );
    $now      = current_time( 'mysql' );

    if ( $existing ) {
        // Never downgrade from completed
        if ( $existing->status === 'completed' && $status === 'in_progress' ) return true;

        $data   = [ 'status' => $status ];
        $format = [ '%s' ];

        if ( $status === 'completed' && empty( $existing->completed_date ) ) {
            $data['completed_date'] = $now;
            $format[]               = '%s';
        }

        return $wpdb->update(
            "{$wpdb->prefix}mfsd_task_progress",
            $data,
            [ 'id' => $existing->id ],
            $format,
            [ '%d' ]
        ) !== false;
    }

    // First write for this student + task
    $insert = [
        'student_id'   => $student_id,
        'course_id'    => $task->course_id,
        'task_slug'    => $task_slug,
        'status'       => $status,
        'started_date' => $now,
    ];
    if ( $status === 'completed' ) {
        $insert['completed_date'] = $now;
    }

    return $wpdb->insert( "{$wpdb->prefix}mfsd_task_progress", $insert ) !== false;
}

/**
 * Get the full progress overview for a student on a course.
 * Used by Parent Portal and future Student Portal.
 *
 * @param  int   $student_id
 * @param  int   $course_id
 * @return array  Keyed by task_slug, each value is an assoc array of task + progress data.
 */
function mfsd_get_course_progress( $student_id, $course_id ) {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT t.task_slug, t.display_name, t.week, t.task_no, t.sequence_order,
                COALESCE(p.status, 'not_started') AS status,
                p.started_date, p.completed_date
         FROM   {$wpdb->prefix}mfsd_task_order t
         LEFT   JOIN {$wpdb->prefix}mfsd_task_progress p
                ON  p.task_slug  = t.task_slug
                AND p.student_id = %d
         WHERE  t.course_id = %d
           AND  t.active    = 1
         ORDER  BY t.sequence_order ASC",
        $student_id,
        $course_id
    ) );

    $result = [];
    foreach ( $rows as $row ) {
        $result[ $row->task_slug ] = [
            'display_name'   => $row->display_name,
            'week'           => (int) $row->week,
            'task_no'        => (int) $row->task_no,
            'sequence_order' => (int) $row->sequence_order,
            'status'         => $row->status,
            'started_date'   => $row->started_date,
            'completed_date' => $row->completed_date,
        ];
    }
    return $result;
}

/**
 * Get all active courses.
 *
 * @return array
 */
function mfsd_get_courses() {
    global $wpdb;
    return $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}mfsd_courses WHERE active = 1 ORDER BY id ASC"
    );
}

/**
 * Return a locked-task HTML message for display in a shortcode.
 * Finds the first incomplete task in the course sequence and names it,
 * so the student knows exactly where to start rather than chasing
 * a chain of locked screens.
 *
 * @param  string $task_slug  The slug of the locked task.
 * @return string             HTML string safe to return from a shortcode.
 */
function mfsd_ordering_locked_message( $task_slug ) {
    global $wpdb;

    $task       = mfsd_get_task_order_row( $task_slug );
    $first_name = 'the first activity in this course';
    $student_id = get_current_user_id();

    if ( $task ) {
        // Find the first task in this course whose progress is not 'completed'
        // for this student — that is the actual place they need to start.
        $first_incomplete = $wpdb->get_row( $wpdb->prepare(
            "SELECT t.display_name
             FROM   {$wpdb->prefix}mfsd_task_order t
             LEFT   JOIN {$wpdb->prefix}mfsd_task_progress p
                    ON  p.task_slug  = t.task_slug
                    AND p.student_id = %d
             WHERE  t.course_id = %d
               AND  t.active    = 1
               AND  ( p.status IS NULL OR p.status != 'completed' )
             ORDER  BY t.sequence_order ASC
             LIMIT  1",
            $student_id,
            $task->course_id
        ) );

        if ( $first_incomplete ) {
            $first_name = $first_incomplete->display_name;
        }
    }

    ob_start();
    ?>
    <div style="max-width:600px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e5e5;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center;font-family:system-ui,-apple-system,sans-serif;">
        <div style="font-size:48px;margin-bottom:16px;">🔒</div>
        <h3 style="margin:0 0 12px;font-size:20px;color:#1d2327;">Activity Locked</h3>
        <p style="color:#555;font-size:16px;line-height:1.6;margin:0 0 20px;">
            You need to complete <strong><?php echo esc_html( $first_name ); ?></strong>
            before you can start this activity.
        </p>
        <a href="javascript:history.back()"
           style="display:inline-block;padding:10px 24px;background:#111;color:#fff;border-radius:10px;text-decoration:none;font-size:14px;font-weight:600;">
            ← Go Back
        </a>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Get badge/coin config for a course, grouped by week.
 * Replaces the WEEK_BADGES / WEEK_CONFIG constants previously hardcoded in
 * mfsd-quest-log — Quest Log's engine and renderer both read this instead.
 *
 * @param  int   $course_id
 * @return array  [ week_num => [ task_slug => [ display_name, badge_slug, badge_image,
 *                                                coin_value, is_rag, counts_for_week_badge ] ] ]
 *                Active tasks only, ordered by sequence_order within each week.
 */
function mfsd_get_course_badge_config( $course_id ) {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT week, task_slug, display_name, badge_slug, badge_image,
                coin_value, is_rag, counts_for_week_badge
         FROM   {$wpdb->prefix}mfsd_task_order
         WHERE  course_id = %d
           AND  active    = 1
         ORDER  BY week ASC, sequence_order ASC",
        $course_id
    ) );

    $result = [];
    foreach ( $rows as $row ) {
        $week = (int) $row->week;
        if ( ! isset( $result[ $week ] ) ) {
            $result[ $week ] = [];
        }
        $result[ $week ][ $row->task_slug ] = [
            'display_name'          => $row->display_name,
            'badge_slug'            => $row->badge_slug,
            'badge_image'           => $row->badge_image,
            'coin_value'            => (int) $row->coin_value,
            'is_rag'                => (bool) $row->is_rag,
            'counts_for_week_badge' => (bool) $row->counts_for_week_badge,
        ];
    }
    return $result;
}

/**
 * Get week titles for a course.
 * Falls back to "Week N" for any week that has active tasks but no row yet
 * in wp_mfsd_course_weeks (e.g. immediately after the schema migration,
 * before a title has been set from Course Manager).
 *
 * @param  int   $course_id
 * @return array  [ week_num => title ]
 */
function mfsd_get_course_week_titles( $course_id ) {
    global $wpdb;

    $rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT week, title FROM {$wpdb->prefix}mfsd_course_weeks WHERE course_id = %d",
        $course_id
    ) );

    $titles = [];
    foreach ( $rows as $row ) {
        $titles[ (int) $row->week ] = $row->title;
    }

    $weeks_with_tasks = $wpdb->get_col( $wpdb->prepare(
        "SELECT DISTINCT week FROM {$wpdb->prefix}mfsd_task_order WHERE course_id = %d AND active = 1",
        $course_id
    ) );
    foreach ( $weeks_with_tasks as $week ) {
        $week = (int) $week;
        if ( ! isset( $titles[ $week ] ) ) {
            $titles[ $week ] = "Week {$week}";
        }
    }

    ksort( $titles );
    return $titles;
}

// ─────────────────────────────────────────────
// MYF-330 — ONE-OFF BACKFILL TOOL (TEMPORARY)
//
// Populates badge_slug/badge_image/coin_value/is_rag/counts_for_week_badge on
// the existing Foundation Course task rows, plus wp_mfsd_course_weeks titles,
// using today's mfsd-quest-log WEEK_BADGES/WEEK_CONFIG values as the source
// of truth (MFSD_CourseManager_QuestLog_Integration_v1_0.md §7).
//
// Matches rows by task_slug and UPDATEs in place — never inserts a row, and
// never touches wp_mfsd_badges / wp_mfsd_wallet. Course is chosen from a
// dropdown rather than a hardcoded course_id, since there is no way to
// confirm the Foundation Course's actual course_id without querying the live
// site. Remove this whole section (and the admin_menu hook below) once the
// backfill has been run and confirmed — see MYF-330 acceptance criteria.
// ─────────────────────────────────────────────

const MFSD_ORDERING_BACKFILL_DATA = [
    1 => [
        'title' => 'Week 1 — Self-Awareness & The Solutions Lens',
        'tasks' => [
            'solution_lens'           => [ 'badge_slug' => 'badge_solution_lens',    'badge_image' => 'badge_solution_lens.png',   'coin_value' => 10, 'is_rag' => 0 ],
            'word_association'        => [ 'badge_slug' => 'badge_word_assoc',       'badge_image' => 'badge_word_assoc.png',      'coin_value' => 10, 'is_rag' => 0 ],
            'personality_test_week_1' => [ 'badge_slug' => 'badge_who_am_i_1',       'badge_image' => 'badge_who_am_i_1.png',      'coin_value' => 10, 'is_rag' => 0 ],
            'super_strengths'         => [ 'badge_slug' => 'badge_super_strengths',  'badge_image' => 'badge_super_strengths.png', 'coin_value' => 10, 'is_rag' => 0 ],
            'rag_week_1'              => [ 'badge_slug' => 'badge_rag_w1',           'badge_image' => 'badge_rag_w1.png',          'coin_value' => 10, 'is_rag' => 1 ],
        ],
    ],
    2 => [
        'title' => 'Week 2 — Interests, Barriers & Dreams into Plans',
        'tasks' => [
            'life_wheel'        => [ 'badge_slug' => 'badge_life_wheel',   'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'junk_jobs'         => [ 'badge_slug' => 'badge_junk_jobs',    'badge_image' => 'badge_junk_jobs.png', 'coin_value' => 10, 'is_rag' => 0 ],
            'favourite_subject' => [ 'badge_slug' => 'badge_fav_subject', 'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'barriers'          => [ 'badge_slug' => 'badge_barriers',    'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'dream_jobs'        => [ 'badge_slug' => 'badge_dream_jobs',  'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'who_am_i_part_2'   => [ 'badge_slug' => 'badge_who_am_i_2',  'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'rag_week_2'        => [ 'badge_slug' => 'badge_rag_w2',      'badge_image' => null, 'coin_value' => 15, 'is_rag' => 1 ],
        ],
    ],
    3 => [
        'title' => 'Week 3 — High Performance & Future Direction',
        'tasks' => [
            'fifty_on_success' => [ 'badge_slug' => 'badge_fifty_quid', 'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'hp_wheel'         => [ 'badge_slug' => 'badge_hp_wheel',   'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'what_is_hp'       => [ 'badge_slug' => 'badge_what_is_hp', 'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'dream_life'       => [ 'badge_slug' => 'badge_dream_life', 'badge_image' => null, 'coin_value' => 10, 'is_rag' => 0 ],
            'rag_week_3'       => [ 'badge_slug' => 'badge_rag_w3',     'badge_image' => null, 'coin_value' => 20, 'is_rag' => 1 ],
        ],
    ],
];

add_action( 'admin_menu', function () {
    add_management_page(
        'MFSD Badge Backfill (MYF-330)',
        'MFSD Badge Backfill',
        'manage_options',
        'mfsd-ordering-backfill',
        'mfsd_ordering_render_backfill_page'
    );
} );

function mfsd_ordering_render_backfill_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $result = null;

    if ( ! empty( $_POST['mfsd_ordering_backfill_nonce'] )
        && wp_verify_nonce( $_POST['mfsd_ordering_backfill_nonce'], 'mfsd_ordering_run_backfill' )
    ) {
        $course_id = (int) ( $_POST['course_id'] ?? 0 );

        if ( $course_id > 0 ) {
            $badge_images_base = plugins_url( 'assets/images/badges/', WP_PLUGIN_DIR . '/mfsd-quest-log/mfsd-quest-log.php' );

            $updated  = [];
            $unmatched = [];

            foreach ( MFSD_ORDERING_BACKFILL_DATA as $week => $week_data ) {
                foreach ( $week_data['tasks'] as $task_slug => $cfg ) {
                    // Check existence first — $wpdb->update()'s affected-row count is 0
                    // both when no row matches AND when a row matches but the values are
                    // already identical (e.g. re-running this after a prior successful
                    // run), so it can't be used on its own to tell "not found" apart
                    // from "already correct".
                    $exists = (int) $wpdb->get_var( $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$wpdb->prefix}mfsd_task_order WHERE course_id = %d AND task_slug = %s",
                        $course_id, $task_slug
                    ) );

                    if ( ! $exists ) {
                        $unmatched[] = $task_slug;
                        continue;
                    }

                    $badge_image_url = $cfg['badge_image'] ? $badge_images_base . $cfg['badge_image'] : null;

                    $wpdb->update(
                        "{$wpdb->prefix}mfsd_task_order",
                        [
                            'badge_slug'            => $cfg['badge_slug'],
                            'badge_image'           => $badge_image_url,
                            'coin_value'            => $cfg['coin_value'],
                            'is_rag'                => $cfg['is_rag'],
                            'counts_for_week_badge' => 1,
                        ],
                        [ 'course_id' => $course_id, 'task_slug' => $task_slug ],
                        [ '%s', '%s', '%d', '%d', '%d' ],
                        [ '%d', '%s' ]
                    );

                    $updated[] = $task_slug;
                }

                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}mfsd_course_weeks (course_id, week, title) VALUES (%d, %d, %s)
                     ON DUPLICATE KEY UPDATE title = VALUES(title)",
                    $course_id, $week, $week_data['title']
                ) );
            }

            update_option( 'mfsd_quest_course_id', $course_id );

            $result = [ 'updated' => $updated, 'unmatched' => $unmatched, 'course_id' => $course_id ];
        }
    }

    $courses = mfsd_get_courses();
    ?>
    <div class="wrap">
        <h1>MFSD Badge Backfill — one-off (MYF-330)</h1>
        <p>Updates existing task rows in <code>wp_mfsd_task_order</code> in place (matched by <code>task_slug</code> — never inserts new rows), upserts the 3 week titles into <code>wp_mfsd_course_weeks</code>, and sets the <code>mfsd_quest_course_id</code> option. Does not touch <code>wp_mfsd_badges</code> or <code>wp_mfsd_wallet</code>.</p>

        <?php if ( $result ) : ?>
            <div class="notice notice-success">
                <p><strong>Backfill run for course_id <?php echo esc_html( $result['course_id'] ); ?>.</strong></p>
                <p>Updated (<?php echo count( $result['updated'] ); ?>): <?php echo esc_html( implode( ', ', $result['updated'] ) ); ?></p>
                <?php if ( $result['unmatched'] ) : ?>
                    <p style="color:#b32d2e;"><strong>Not found in wp_mfsd_task_order for this course (<?php echo count( $result['unmatched'] ); ?>):</strong> <?php echo esc_html( implode( ', ', $result['unmatched'] ) ); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?php wp_nonce_field( 'mfsd_ordering_run_backfill', 'mfsd_ordering_backfill_nonce' ); ?>
            <table class="form-table">
                <tr>
                    <th><label for="course_id">Course (select the Foundation Course)</label></th>
                    <td>
                        <select name="course_id" id="course_id" required>
                            <option value="">— Select —</option>
                            <?php foreach ( $courses as $course ) : ?>
                                <option value="<?php echo esc_attr( $course->id ); ?>"><?php echo esc_html( $course->course_name . ' (' . $course->course_slug . ', id=' . $course->id . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Run Backfill' ); ?>
        </form>
    </div>
    <?php
}