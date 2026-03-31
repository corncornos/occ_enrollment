<?php
declare(strict_types=1);

/**
 * AuditLog Class
 * Handles logging of admin actions for audit purposes
 */
class AuditLog {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Log an admin action
     * 
     * @param array $data Array containing:
     *   - admin_id (int): ID of admin performing action
     *   - admin_name (string): Name of admin
     *   - action_type (string): Type of action
     *   - action_description (string): Description of action
     *   - entity_type (string|null): Type of entity affected
     *   - entity_id (int|null): ID of entity affected
     *   - old_values (array|null): Old values before change
     *   - new_values (array|null): New values after change
     *   - status (string): 'success', 'failed', or 'partial'
     *   - error_message (string|null): Error message if failed
     * @return bool True on success, false on failure
     */
    public function log(array $data): bool {
        try {
            $admin_id = $data['admin_id'] ?? null;
            $admin_name = $data['admin_name'] ?? 'Unknown';
            $action_type = $data['action_type'] ?? 'unknown_action';
            $action_description = $data['action_description'] ?? '';
            $entity_type = $data['entity_type'] ?? null;
            $entity_id = $data['entity_id'] ?? null;
            $old_values = isset($data['old_values']) ? json_encode($data['old_values'], JSON_UNESCAPED_UNICODE) : null;
            $new_values = isset($data['new_values']) ? json_encode($data['new_values'], JSON_UNESCAPED_UNICODE) : null;
            $status = $data['status'] ?? 'success';
            $error_message = $data['error_message'] ?? null;
            
            // Get IP address and user agent
            $ip_address = $this->getIpAddress();
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $sql = "INSERT INTO audit_logs (
                admin_id, admin_name, action_type, action_description,
                entity_type, entity_id, old_values, new_values,
                ip_address, user_agent, status, error_message
            ) VALUES (
                :admin_id, :admin_name, :action_type, :action_description,
                :entity_type, :entity_id, :old_values, :new_values,
                :ip_address, :user_agent, :status, :error_message
            )";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
            $stmt->bindParam(':admin_name', $admin_name);
            $stmt->bindParam(':action_type', $action_type);
            $stmt->bindParam(':action_description', $action_description);
            $stmt->bindParam(':entity_type', $entity_type);
            $stmt->bindParam(':entity_id', $entity_id, PDO::PARAM_INT);
            $stmt->bindParam(':old_values', $old_values);
            $stmt->bindParam(':new_values', $new_values);
            $stmt->bindParam(':ip_address', $ip_address);
            $stmt->bindParam(':user_agent', $user_agent);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':error_message', $error_message);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            // Silent fail - don't break the application if logging fails
            error_log('Audit log error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get audit logs with filters
     * 
     * @param array $filters Array of filter options:
     *   - admin_id (int|null): Filter by admin ID
     *   - action_type (string|null): Filter by action type
     *   - entity_type (string|null): Filter by entity type
     *   - entity_id (int|null): Filter by entity ID
     *   - date_from (string|null): Start date (Y-m-d)
     *   - date_to (string|null): End date (Y-m-d)
     *   - status (string|null): Filter by status
     *   - limit (int): Limit results (default: 100)
     *   - offset (int): Offset for pagination (default: 0)
     * @return array Array of audit log records
     */
    public function getLogs(array $filters = []): array {
        try {
            $admin_id = $filters['admin_id'] ?? null;
            $action_type = $filters['action_type'] ?? null;
            $entity_type = $filters['entity_type'] ?? null;
            $entity_id = $filters['entity_id'] ?? null;
            $date_from = $filters['date_from'] ?? null;
            $date_to = $filters['date_to'] ?? null;
            $status = $filters['status'] ?? null;
            $limit = $filters['limit'] ?? 100;
            $offset = $filters['offset'] ?? 0;
            
            $sql = "SELECT * FROM audit_logs WHERE 1=1";
            $params = [];
            
            if ($admin_id !== null) {
                $sql .= " AND admin_id = :admin_id";
                $params[':admin_id'] = $admin_id;
            }
            
            if ($action_type !== null && $action_type !== '') {
                $sql .= " AND action_type = :action_type";
                $params[':action_type'] = $action_type;
            }
            
            if ($entity_type !== null && $entity_type !== '') {
                $sql .= " AND entity_type = :entity_type";
                $params[':entity_type'] = $entity_type;
            }
            
            if ($entity_id !== null) {
                $sql .= " AND entity_id = :entity_id";
                $params[':entity_id'] = $entity_id;
            }
            
            if ($date_from !== null) {
                $sql .= " AND DATE(created_at) >= :date_from";
                $params[':date_from'] = $date_from;
            }
            
            if ($date_to !== null) {
                $sql .= " AND DATE(created_at) <= :date_to";
                $params[':date_to'] = $date_to;
            }
            
            if ($status !== null && $status !== '') {
                $sql .= " AND status = :status";
                $params[':status'] = $status;
            }
            
            $sql .= " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
            
            $stmt = $this->conn->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Decode JSON values
            foreach ($logs as &$log) {
                if (!empty($log['old_values'])) {
                    $log['old_values'] = json_decode($log['old_values'], true);
                }
                if (!empty($log['new_values'])) {
                    $log['new_values'] = json_decode($log['new_values'], true);
                }
            }
            
            return $logs;
            
        } catch(PDOException $e) {
            error_log('Error fetching audit logs: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get count of audit logs with filters
     * 
     * @param array $filters Same as getLogs filters
     * @return int Count of logs
     */
    public function getLogCount(array $filters = []): int {
        try {
            $admin_id = $filters['admin_id'] ?? null;
            $action_type = $filters['action_type'] ?? null;
            $entity_type = $filters['entity_type'] ?? null;
            $entity_id = $filters['entity_id'] ?? null;
            $date_from = $filters['date_from'] ?? null;
            $date_to = $filters['date_to'] ?? null;
            $status = $filters['status'] ?? null;
            
            $sql = "SELECT COUNT(*) as total FROM audit_logs WHERE 1=1";
            $params = [];
            
            if ($admin_id !== null) {
                $sql .= " AND admin_id = :admin_id";
                $params[':admin_id'] = $admin_id;
            }
            
            if ($action_type !== null && $action_type !== '') {
                $sql .= " AND action_type = :action_type";
                $params[':action_type'] = $action_type;
            }
            
            if ($entity_type !== null && $entity_type !== '') {
                $sql .= " AND entity_type = :entity_type";
                $params[':entity_type'] = $entity_type;
            }
            
            if ($entity_id !== null) {
                $sql .= " AND entity_id = :entity_id";
                $params[':entity_id'] = $entity_id;
            }
            
            if ($date_from !== null) {
                $sql .= " AND DATE(created_at) >= :date_from";
                $params[':date_from'] = $date_from;
            }
            
            if ($date_to !== null) {
                $sql .= " AND DATE(created_at) <= :date_to";
                $params[':date_to'] = $date_to;
            }
            
            if ($status !== null && $status !== '') {
                $sql .= " AND status = :status";
                $params[':status'] = $status;
            }
            
            $stmt = $this->conn->prepare($sql);
            
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return (int)($result['total'] ?? 0);
            
        } catch(PDOException $e) {
            error_log('Error counting audit logs: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Get distinct action types
     * 
     * @return array Array of action types
     */
    public function getActionTypes(): array {
        try {
            $sql = "SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get distinct entity types
     * 
     * @return array Array of entity types
     */
    public function getEntityTypes(): array {
        try {
            $sql = "SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get IP address of the client
     * 
     * @return string IP address
     */
    private function getIpAddress(): string {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 
                    'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip);
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                        return $ip;
                    }
                }
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
?>

