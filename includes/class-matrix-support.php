<?php
/**
 * Support Ticket System
 */

if (!defined('ABSPATH')) {
    exit;
}

class Matrix_MLM_Support {

    /**
     * Create a ticket
     */
    public function create_ticket($user_id, $subject, $message, $priority = 'medium', $department = null) {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'matrix_tickets', [
            'user_id' => $user_id,
            'subject' => $subject,
            'priority' => $priority,
            'department' => $department,
            'status' => 'open'
        ]);

        $ticket_id = $wpdb->insert_id;

        // Add initial message
        $wpdb->insert($wpdb->prefix . 'matrix_ticket_messages', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'message' => $message,
            'is_admin' => 0
        ]);

        // Send notification to admin
        Matrix_MLM_Notifications::send_admin_notification(
            'new_ticket',
            sprintf(__('New support ticket #%d: %s', 'matrix-mlm'), $ticket_id, $subject)
        );

        return $ticket_id;
    }

    /**
     * Reply to ticket
     */
    public function reply($ticket_id, $user_id, $message, $is_admin = false) {
        global $wpdb;

        $wpdb->insert($wpdb->prefix . 'matrix_ticket_messages', [
            'ticket_id' => $ticket_id,
            'user_id' => $user_id,
            'message' => $message,
            'is_admin' => $is_admin ? 1 : 0
        ]);

        // Update ticket status
        $new_status = $is_admin ? 'answered' : 'customer_reply';
        $wpdb->update(
            $wpdb->prefix . 'matrix_tickets',
            ['status' => $new_status],
            ['id' => $ticket_id]
        );

        return true;
    }

    /**
     * Close ticket
     */
    public function close_ticket($ticket_id) {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'matrix_tickets',
            ['status' => 'closed'],
            ['id' => $ticket_id]
        );
    }

    /**
     * Get user tickets
     */
    public function get_user_tickets($user_id, $status = null, $limit = 20, $offset = 0) {
        global $wpdb;

        $where = "WHERE user_id = %d";
        $params = [$user_id];

        if ($status) {
            $where .= " AND status = %s";
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}matrix_tickets $where ORDER BY updated_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Get all tickets (admin)
     */
    public function get_all_tickets($status = null, $limit = 20, $offset = 0) {
        global $wpdb;

        $where = "WHERE 1=1";
        $params = [];

        if ($status) {
            $where .= " AND t.status = %s";
            $params[] = $status;
        }

        $params[] = $limit;
        $params[] = $offset;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT t.*, u.user_login, u.user_email 
             FROM {$wpdb->prefix}matrix_tickets t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID 
             $where ORDER BY t.updated_at DESC LIMIT %d OFFSET %d",
            $params
        ));
    }

    /**
     * Get ticket messages
     */
    public function get_messages($ticket_id) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT m.*, u.user_login, u.user_email 
             FROM {$wpdb->prefix}matrix_ticket_messages m 
             LEFT JOIN {$wpdb->users} u ON m.user_id = u.ID 
             WHERE m.ticket_id = %d ORDER BY m.created_at ASC",
            $ticket_id
        ));
    }

    /**
     * Get ticket by ID
     */
    public function get_ticket($ticket_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT t.*, u.user_login, u.user_email 
             FROM {$wpdb->prefix}matrix_tickets t 
             LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID 
             WHERE t.id = %d",
            $ticket_id
        ));
    }
}
