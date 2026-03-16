<?php
/**
 * Plugin Name: MFSD Ordering Utility
 * Description: Shared course ordering, task sequencing and student progress utility for all MFSD plugins.
 * Version:     1.1.0
 * Author:      MisterT9007
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'MFSD_ORDERING_VERSION', '1.1.0' );

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
        INDEX idx_course_seq  (course_id, sequence_order),
        INDEX idx_task_slug   (task_slug)
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
 * Shows the name of the preceding task the student must complete first.
 *
 * @param  string $task_slug  The slug of the locked task.
 * @return string             HTML string safe to return from a shortcode.
 */
function mfsd_ordering_locked_message( $task_slug ) {
    global $wpdb;

    $task      = mfsd_get_task_order_row( $task_slug );
    $prev_name = 'the previous activity';

    if ( $task && (int) $task->sequence_order > 1 ) {
        $prev = $wpdb->get_row( $wpdb->prepare(
            "SELECT display_name
             FROM   {$wpdb->prefix}mfsd_task_order
             WHERE  course_id       = %d
               AND  sequence_order  = %d
               AND  active          = 1
             LIMIT  1",
            $task->course_id,
            (int) $task->sequence_order - 1
        ) );
        if ( $prev ) {
            $prev_name = $prev->display_name;
        }
    }

    ob_start();
    ?>
    <div style="max-width:600px;margin:40px auto;padding:32px;background:#fff;border:1px solid #e5e5e5;border-radius:14px;box-shadow:0 2px 8px rgba(0,0,0,.06);text-align:center;font-family:system-ui,-apple-system,sans-serif;">
        <div style="font-size:48px;margin-bottom:16px;">🔒</div>
        <h3 style="margin:0 0 12px;font-size:20px;color:#1d2327;">Activity Locked</h3>
        <p style="color:#555;font-size:16px;line-height:1.6;margin:0 0 20px;">
            You need to complete <strong><?php echo esc_html( $prev_name ); ?></strong>
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