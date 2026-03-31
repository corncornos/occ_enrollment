<?php
/**
 * Enrollment Workflow Helper Functions
 * Handles the enrollment approval workflow:
 * - Regular: Student → Registrar → Admin
 * - Irregular: Student → Program Head → Registrar → Admin
 */

declare(strict_types=1);

require_once '../config/database.php';

/**
 * Record an approval action in the enrollment_approvals table
 * 
 * @param PDO $conn Database connection
 * @param int $enrollment_id Enrollment request ID
 * @param string $approver_role Role of approver (program_head, registrar, admin)
 * @param int $approver_id User ID of approver
 * @param string $action Action taken (approved, rejected, modified)
 * @param string|null $remarks Optional remarks
 * @return bool Success status
 */
function recordEnrollmentApproval(PDO $conn, int $enrollment_id, string $approver_role, int $approver_id, string $action, ?string $remarks = null): bool {
    try {
        // Check if table exists first
        $check_table = $conn->query("SHOW TABLES LIKE 'enrollment_approvals'");
        if ($check_table->rowCount() == 0) {
            error_log('Error: enrollment_approvals table does not exist. Please run database migration.');
            return false;
        }
        
        $sql = "INSERT INTO enrollment_approvals 
                (enrollment_id, approver_role, approver_id, action, remarks, approved_at)
                VALUES (:enrollment_id, :approver_role, :approver_id, :action, :remarks, NOW())";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':enrollment_id', $enrollment_id, PDO::PARAM_INT);
        $stmt->bindParam(':approver_role', $approver_role);
        $stmt->bindParam(':approver_id', $approver_id, PDO::PARAM_INT);
        $stmt->bindParam(':action', $action);
        $stmt->bindParam(':remarks', $remarks);
        $result = $stmt->execute();
        
        if (!$result) {
            $error_info = $stmt->errorInfo();
            error_log('Error recording enrollment approval - SQL Error: ' . print_r($error_info, true));
            return false;
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('Error recording enrollment approval: ' . $e->getMessage());
        error_log('SQL State: ' . $e->getCode());
        return false;
    }
}

/**
 * Update enrollment status and record approval
 * 
 * @param PDO $conn Database connection
 * @param int $enrollment_id Enrollment request ID
 * @param string $new_status New status (pending_program_head, pending_registrar, pending_admin, confirmed, rejected)
 * @param string $approver_role Role of approver
 * @param int $approver_id User ID of approver
 * @param string $action Action taken (approved, rejected, modified)
 * @param string|null $remarks Optional remarks
 * @param bool $manage_transaction Whether to manage transaction (default: true). Set to false if already in a transaction.
 * @return bool Success status
 */
function updateEnrollmentStatus(PDO $conn, int $enrollment_id, string $new_status, string $approver_role, int $approver_id, string $action, ?string $remarks = null, bool $manage_transaction = true): bool {
    try {
        $already_in_transaction = $conn->inTransaction();
        
        if ($manage_transaction && !$already_in_transaction) {
            $conn->beginTransaction();
        }
        
        // Update enrollment status
        $update_sql = "UPDATE next_semester_enrollments 
                      SET request_status = :new_status,
                          processed_by = :approver_id,
                          processed_at = NOW(),
                          updated_at = NOW()
                      WHERE id = :enrollment_id";
        $update_stmt = $conn->prepare($update_sql);
        $update_stmt->bindParam(':new_status', $new_status);
        $update_stmt->bindParam(':approver_id', $approver_id, PDO::PARAM_INT);
        $update_stmt->bindParam(':enrollment_id', $enrollment_id, PDO::PARAM_INT);
        
        // Log the update attempt for debugging
        error_log("Attempting to update enrollment status - ID: $enrollment_id, New Status: $new_status, Approver: $approver_id");
        
        $update_result = $update_stmt->execute();
        
        if (!$update_result) {
            $error_info = $update_stmt->errorInfo();
            $error_msg = 'Failed to update enrollment status in database. ';
            $error_msg .= 'SQL State: ' . ($error_info[0] ?? 'Unknown') . '. ';
            $error_msg .= 'Error Code: ' . ($error_info[1] ?? 'Unknown') . '. ';
            $error_msg .= 'Error Message: ' . ($error_info[2] ?? 'Unknown error');
            error_log('Error updating enrollment status - Full Error Info: ' . print_r($error_info, true));
            error_log('SQL Query: ' . $update_sql);
            error_log('Parameters - new_status: ' . $new_status . ', approver_id: ' . $approver_id . ', enrollment_id: ' . $enrollment_id);
            throw new Exception($error_msg);
        }
        
        // Verify the update actually changed a row OR status is already correct
        $rows_affected = $update_stmt->rowCount();
        if ($rows_affected == 0) {
            error_log("Warning: Update executed but no rows were affected. Enrollment ID: $enrollment_id");
            // Check if the enrollment exists and what its current status is
            $check_sql = "SELECT id, request_status FROM next_semester_enrollments WHERE id = :enrollment_id";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bindParam(':enrollment_id', $enrollment_id, PDO::PARAM_INT);
            $check_stmt->execute();
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$existing) {
                throw new Exception("Enrollment request with ID $enrollment_id does not exist.");
            } else {
                $current_status = $existing['request_status'] ?? null;
                error_log("Enrollment exists with current status: " . ($current_status ?? 'NULL'));
                // If status is already what we want, consider it a success (idempotent operation)
                if ($current_status == $new_status) {
                    error_log("Status is already $new_status, treating as success (idempotent operation)");
                    // Still try to record approval if it doesn't exist yet
                    // This is fine - the status update succeeded (it's already correct)
                } else {
                    // Status is different but update didn't affect rows - this is an error
                    throw new Exception("Update executed but no rows affected. Current status: " . ($current_status ?? 'NULL') . ", attempted status: $new_status");
                }
            }
        } else {
            error_log("Successfully updated enrollment status - Rows affected: $rows_affected");
        }
        
        // Record approval action (only if table exists, otherwise skip but log warning)
        $approval_recorded = recordEnrollmentApproval($conn, $enrollment_id, $approver_role, $approver_id, $action, $remarks);
        
        if (!$approval_recorded) {
            // Log warning but don't fail if table doesn't exist - allow status update to proceed
            error_log('Warning: Failed to record approval action, but continuing with status update. Enrollment ID: ' . $enrollment_id);
            // Don't throw exception - allow the status update to succeed even if approval logging fails
            // This is a graceful degradation
        }
        
        if ($manage_transaction && !$already_in_transaction) {
            $conn->commit();
        }
        return true;
    } catch (PDOException $e) {
        if ($manage_transaction && $conn->inTransaction() && !$already_in_transaction) {
            $conn->rollBack();
        }
        error_log('PDO Error updating enrollment status: ' . $e->getMessage());
        error_log('SQL State: ' . $e->getCode());
        return false;
    } catch (Exception $e) {
        if ($manage_transaction && $conn->inTransaction() && !$already_in_transaction) {
            $conn->rollBack();
        }
        error_log('Error updating enrollment status: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        return false;
    }
}

/**
 * Mark COR as generated for an enrollment
 * 
 * @param PDO $conn Database connection
 * @param int $enrollment_id Enrollment request ID
 * @param int $registrar_id Registrar user ID who generated the COR
 * @return bool Success status
 */
function markCORGenerated(PDO $conn, int $enrollment_id, int $registrar_id): bool {
    try {
        $sql = "UPDATE next_semester_enrollments 
                SET cor_generated = TRUE,
                    cor_generated_at = NOW(),
                    cor_generated_by = :registrar_id
                WHERE id = :enrollment_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':enrollment_id', $enrollment_id, PDO::PARAM_INT);
        $stmt->bindParam(':registrar_id', $registrar_id, PDO::PARAM_INT);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log('Error marking COR as generated: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get enrollment approval history
 * 
 * @param PDO $conn Database connection
 * @param int $enrollment_id Enrollment request ID
 * @return array Array of approval records
 */
function getEnrollmentApprovalHistory(PDO $conn, int $enrollment_id): array {
    try {
        $sql = "SELECT ea.*, u.first_name, u.last_name, u.email
                FROM enrollment_approvals ea
                JOIN users u ON ea.approver_id = u.id
                WHERE ea.enrollment_id = :enrollment_id
                ORDER BY ea.approved_at ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':enrollment_id', $enrollment_id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('Error getting enrollment approval history: ' . $e->getMessage());
        return [];
    }
}

