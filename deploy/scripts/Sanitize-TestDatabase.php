<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// Deliberately use PDO without booting Kimai or loading plugins against raw data.
if (getenv('TEST_RESTORE_TARGET') !== 'test-kimai') {
    throw new RuntimeException('This sanitizer only runs in the test-kimai restore container.');
}
$url = parse_url(getenv('DATABASE_URL'));
if ($url === false || ($url['host'] ?? '') !== 'sqldb' || ($url['path'] ?? '') !== '/kimai' || ($url['user'] ?? '') !== 'kimai') {
    throw new RuntimeException('Unexpected test database connection.');
}
$db = new PDO('mysql:host=sqldb;dbname=kimai;charset=utf8mb4', 'kimai', rawurldecode($url['pass']), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$mode = $argv[1] ?? '';
if (!in_array($mode, ['', '--sanitize', '--verify', '--verify-seed'], true)) {
    throw new InvalidArgumentException('Unknown sanitizer mode.');
}
$profile = strtolower($argv[2] ?? 'sanitized');
if (!in_array($profile, ['sanitized', 'business'], true)) {
    throw new InvalidArgumentException('Unknown data profile.');
}
$verifyOnly = !in_array($mode, ['', '--sanitize'], true);
$verifyAdmin = $mode === '--verify';

// Every retained column is reviewed here. Additional tables/columns fail closed.
// Emptying a table also handles any new columns in that table without retaining data.
$purge = [
    'kimai2_access_token', 'kimai2_sessions', 'kimai2_user_preferences', 'kimai2_bookmarks', 'kimai2_configuration',
    'kimai2_activities_meta', 'kimai2_customers_meta', 'kimai2_projects_meta', 'kimai2_timesheet_meta',
    'kimai2_customers_comments', 'kimai2_projects_comments', 'kimai2_invoices_meta',
    'kimai2_invoice_templates_meta', 'kimai2_invoices', 'kimai2_invoice_templates',
    'kimai2_export_templates', 'kimai2_roles_permissions', 'kimai2_roles',
];
$authenticationConfiguration = [
    'saml.activate' => '0',
    'ldap.activate' => '0',
    'user.login' => '1',
];
$credentialConfiguration = [
    'ldap.connection.password' => '',
    'saml.connection.sp.privateKey' => '',
    'calendar.google.api_key' => '',
];
$scrubbedConfiguration = $authenticationConfiguration + $credentialConfiguration;
$budget = ['budget' => '0', 'time_budget' => '0', 'budget_type' => 'NULL'];
$color = ['color' => "'#4c78a8'"];
$policies = [
    'kimai2_users' => [
        'keep' => 'id supervisor_id',
        'set' => [
            'username' => "CONCAT('test-user-', id)", 'email' => "CONCAT('user-', id, '@example.invalid')",
            'alias' => "CONCAT('User ', id)", 'title' => 'NULL', 'avatar' => 'NULL', 'account' => 'NULL',
            'password' => "''", 'api_token' => 'NULL', 'confirmation_token' => 'NULL',
            'totp_secret' => 'NULL', 'totp_enabled' => '0', 'password_requested_at' => 'NULL',
            'last_login' => 'NULL', 'registration_date' => "'2020-01-01 00:00:00'", 'signature_date' => 'NULL',
            'enabled' => '0', 'system_account' => '0', 'auth' => "'kimai'", 'roles' => "JSON_ARRAY('ROLE_USER')",
        ] + $color,
    ],
    'kimai2_customers' => [
        'keep' => 'id visible billable',
        'set' => [
            'name' => "CONCAT('Customer ', id)", 'number' => 'NULL', 'comment' => 'NULL', 'company' => 'NULL',
            'vat_id' => 'NULL', 'contact' => 'NULL', 'address' => 'NULL', 'country' => "'SE'", 'language' => "'en'",
            'currency' => "'SEK'", 'phone' => 'NULL', 'fax' => 'NULL', 'mobile' => 'NULL', 'email' => 'NULL',
            'homepage' => 'NULL', 'timezone' => "'Europe/Stockholm'", 'invoice_template_id' => 'NULL',
            'invoice_text' => 'NULL', 'address_line1' => 'NULL', 'address_line2' => 'NULL', 'address_line3' => 'NULL',
            'postcode' => 'NULL', 'city' => 'NULL', 'invoice_email' => 'NULL', 'buyer_reference' => 'NULL', 'created_at' => 'NULL',
        ] + $budget + $color,
    ],
    'kimai2_projects' => [
        'keep' => 'id customer_id visible billable global_activities order_date start end locked_until',
        'set' => [
            'name' => "CONCAT('Project ', id)", 'number' => 'NULL', 'order_number' => 'NULL',
            'comment' => 'NULL', 'invoice_text' => 'NULL', 'timezone' => "'Europe/Stockholm'", 'created_at' => 'NULL',
        ] + $budget + $color,
    ],
    'kimai2_activities' => [
        'keep' => 'id project_id visible billable',
        'set' => ['name' => "CONCAT('Activity ', id)", 'number' => 'NULL', 'comment' => 'NULL', 'invoice_text' => 'NULL', 'created_at' => 'NULL'] + $budget + $color,
    ],
    'kimai2_timesheet' => [
        'keep' => 'id user activity_id project_id date_tz start_time end_time duration break exported billable timezone',
        'set' => [
            'description' => 'NULL', 'category' => "'work'", 'modified_at' => 'NULL',
            'rate' => 'CASE WHEN fixed_rate IS NOT NULL THEN 100 ELSE ROUND(COALESCE(duration, 0) / 3600 * 100, 2) END',
            'internal_rate' => 'CASE WHEN internal_rate IS NULL THEN NULL ELSE rate / 2 END',
            'fixed_rate' => 'CASE WHEN fixed_rate IS NULL THEN NULL ELSE 100 END',
            'hourly_rate' => 'CASE WHEN hourly_rate IS NULL THEN NULL ELSE 100 END',
        ],
    ],
    'kimai2_teams' => ['keep' => 'id', 'set' => ['name' => "CONCAT('Team ', id)"] + $color],
    'kimai2_tags' => ['keep' => 'id visible', 'set' => ['name' => "CONCAT('Tag ', id)"] + $color],
    'kimai2_users_teams' => ['keep' => 'id user_id team_id teamlead', 'set' => []],
    'kimai2_customers_teams' => ['keep' => 'customer_id team_id', 'set' => []],
    'kimai2_projects_teams' => ['keep' => 'project_id team_id', 'set' => []],
    'kimai2_activities_teams' => ['keep' => 'activity_id team_id', 'set' => []],
    'kimai2_timesheet_tags' => ['keep' => 'timesheet_id tag_id', 'set' => []],
    'kimai2_working_times' => ['keep' => 'id user_id date expected actual approved_by approved_at', 'set' => []],
    'migration_versions' => ['keep' => 'version executed_at execution_time', 'set' => []],
];
foreach (['activities' => 'activity_id', 'customers' => 'customer_id', 'projects' => 'project_id'] as $entity => $foreignKey) {
    $policies['kimai2_' . $entity . '_rates'] = [
        'keep' => 'id user_id fixed ' . $foreignKey,
        'set' => ['rate' => '100', 'internal_rate' => 'CASE WHEN internal_rate IS NULL THEN NULL ELSE 50 END'],
    ];
}

// Opt-in private business copy: preserve identities, enabled flags, financial data,
// preferences, metadata and documents' database records. Remove login secrets only.
if ($profile === 'business') {
    $credentials = [
        'password' => "''", 'api_token' => 'NULL', 'confirmation_token' => 'NULL',
        'totp_secret' => 'NULL', 'totp_enabled' => '0', 'password_requested_at' => 'NULL', 'auth' => "'kimai'",
    ];
    foreach ($policies as $name => &$policy) {
        $fields = array_merge(explode(' ', $policy['keep']), array_keys($policy['set']));
        $policy['set'] = $name === 'kimai2_users' ? $credentials : [];
        $policy['keep'] = implode(' ', array_diff($fields, array_keys($policy['set'])));
    }
    unset($policy);
    // Reviewed core schemas, including the tables discarded by the anonymous mode.
    $businessTables = [
        'user_preferences' => 'id user_id name value',
        'bookmarks' => 'id user_id type name content',
        'configuration' => 'id name value',
        'activities_meta' => 'id activity_id name value visible',
        'customers_meta' => 'id customer_id name value visible',
        'projects_meta' => 'id project_id name value visible',
        'timesheet_meta' => 'id timesheet_id name value visible',
        'customers_comments' => 'id customer_id created_by_id message created_at pinned',
        'projects_comments' => 'id project_id created_by_id message created_at pinned',
        'invoices_meta' => 'id invoice_id name value visible',
        'invoice_templates_meta' => 'id template_id name value visible',
        'invoices' => 'id customer_id user_id invoice_number created_at timezone total tax currency status due_days vat invoice_filename payment_date comment',
        'invoice_templates' => 'id name title company address due_days vat calculator number_generator renderer payment_terms vat_id contact payment_details language customer_id',
        'export_templates' => 'id title renderer language columns options',
        'roles_permissions' => 'id role_id permission allowed',
        'roles' => 'id name',
    ];
    foreach ($businessTables as $name => $fields) {
        $policies['kimai2_' . $name] = ['keep' => $fields, 'set' => []];
    }
    $purge = ['kimai2_access_token', 'kimai2_sessions'];
}

function identifier(string $name): string
{
    if (preg_match('/^[a-zA-Z0-9_]+$/D', $name) !== 1) {
        throw new RuntimeException('Unsupported database identifier.');
    }

    return '`' . $name . '`';
}

function businessFingerprints(PDO $db, array $policies, array $columns, array $excludedConfigurationNames): array
{
    $result = [];
    $buffered = $db->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
    try {
        foreach ($policies as $name => $policy) {
            if (!isset($columns[$name])) {
                continue;
            }
            $fields = array_intersect(explode(' ', $policy['keep']), array_column($columns[$name], 'Field'));
            $selection = implode(', ', array_map(identifier(...), $fields));
            $keys = $db->query('SHOW INDEX FROM ' . identifier($name) . " WHERE Key_name = 'PRIMARY'")->fetchAll(PDO::FETCH_ASSOC);
            usort($keys, fn ($left, $right) => (int) $left['Seq_in_index'] <=> (int) $right['Seq_in_index']);
            if ($keys === []) {
                throw new RuntimeException('Business fingerprints require a primary key.');
            }
            $order = implode(', ', array_map(identifier(...), array_column($keys, 'Column_name')));
            $where = $name === 'kimai2_configuration' && $excludedConfigurationNames !== []
                ? ' WHERE name NOT IN (' . implode(', ', array_map($db->quote(...), $excludedConfigurationNames)) . ')' : '';
            $rows = $db->query('SELECT ' . $selection . ' FROM ' . identifier($name) . $where . ' ORDER BY ' . $order);
            $hash = hash_init('sha256');
            while ($row = $rows->fetch(PDO::FETCH_NUM)) {
                hash_update($hash, serialize($row));
            }
            $rows->closeCursor();
            $result[$name] = hash_final($hash);
        }
    } finally {
        $db->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
    }

    return $result;
}

try {
    if (!str_starts_with((string) $db->query('SELECT VERSION()')->fetchColumn(), '11.4.')) {
        throw new RuntimeException('Restore requires MariaDB 11.4.');
    }
    $tables = $db->query('SELECT TABLE_NAME, TABLE_TYPE, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
    $columns = [];
    foreach ($tables as $table) {
        $name = $table['TABLE_NAME'];
        if ($table['TABLE_TYPE'] !== 'BASE TABLE' || $table['ENGINE'] !== 'InnoDB' || (!isset($policies[$name]) && !in_array($name, $purge, true))) {
            throw new RuntimeException('Unsupported table, view or engine: ' . $name . '. Review the sanitization policy.');
        }
        $columns[$name] = $db->query('SHOW COLUMNS FROM ' . identifier($name))->fetchAll(PDO::FETCH_ASSOC);
        if (!isset($policies[$name])) {
            continue;
        }
        $keep = explode(' ', $policies[$name]['keep']);
        $allowed = array_merge($keep, array_keys($policies[$name]['set']));
        foreach ($columns[$name] as $column) {
            if (!in_array($column['Field'], $allowed, true)) {
                throw new RuntimeException('Unsupported column: ' . $name . '.' . $column['Field'] . '. Review the sanitization policy.');
            }
            if ($profile === 'sanitized' && in_array($column['Field'], $keep, true) && !in_array($column['Field'], ['version', 'timezone'], true)
                && preg_match('/^(tinyint|smallint|mediumint|int|bigint|date|datetime|timestamp)\b/', $column['Type']) !== 1) {
                throw new RuntimeException('Unexpected retained column type.');
            }
        }
    }
    foreach (['kimai2_users', 'kimai2_customers', 'kimai2_projects', 'kimai2_activities', 'kimai2_timesheet', 'migration_versions'] as $required) {
        if (!isset($columns[$required])) {
            throw new RuntimeException('Backup is missing a required core table.');
        }
    }
    foreach (['TRIGGERS' => 'TRIGGER_SCHEMA', 'ROUTINES' => 'ROUTINE_SCHEMA', 'EVENTS' => 'EVENT_SCHEMA'] as $type => $schema) {
        if ((int) $db->query('SELECT COUNT(*) FROM information_schema.' . $type . ' WHERE ' . $schema . ' = DATABASE()')->fetchColumn() !== 0) {
            throw new RuntimeException('Stored routines, triggers and events need a separate sanitization review.');
        }
    }
    foreach ($db->query('SELECT version FROM migration_versions')->fetchAll(PDO::FETCH_COLUMN) as $version) {
        if (preg_match('/^(DoctrineMigrations\\\\)?Version[0-9]{14}$/D', $version) !== 1) {
            throw new RuntimeException('Unsupported migration version.');
        }
        $className = basename(str_replace('\\', '/', $version));
        if (!is_file('/opt/kimai/migrations/' . $className . '.php')) {
            throw new RuntimeException('Backup contains a migration unavailable in this checkout. Use matching/newer source or review plugin migrations.');
        }
    }
    $timezones = array_merge(DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), ['UTC']);
    foreach ($db->query('SELECT DISTINCT timezone FROM kimai2_timesheet')->fetchAll(PDO::FETCH_COLUMN) as $timezone) {
        if (!in_array($timezone, $timezones, true)) {
            throw new RuntimeException('Unsupported timesheet timezone.');
        }
    }
    $before = $db->query('SELECT COUNT(*) AS records, COALESCE(SUM(duration), 0) AS seconds FROM kimai2_timesheet')->fetch(PDO::FETCH_ASSOC);
    $businessBefore = $profile === 'business' && !$verifyOnly ? businessFingerprints($db, $policies, $columns, array_keys($scrubbedConfiguration)) : null;
    if (!$verifyOnly) {
        $db->beginTransaction();
        foreach ($purge as $name) {
            if (isset($columns[$name])) {
                $db->exec('DELETE FROM ' . identifier($name));
            }
        }
        foreach ($policies as $name => $policy) {
            if (!isset($columns[$name]) || $policy['set'] === []) {
                continue;
            }
            $actual = array_column($columns[$name], 'Field');
            // Temporary unique values avoid collisions with existing names/emails.
            foreach (['username', 'email', 'name'] as $unique) {
                if (isset($policy['set'][$unique])) {
                    $prefix = $db->quote('restore-' . bin2hex(random_bytes(12)) . '-');
                    $db->exec('UPDATE ' . identifier($name) . ' SET ' . identifier($unique) . ' = CONCAT(' . $prefix . ', id)');
                }
            }
            $assignments = [];
            foreach ($policy['set'] as $column => $value) {
                if (in_array($column, $actual, true)) {
                    $assignments[] = identifier($column) . ' = ' . $value;
                }
            }
            $db->exec('UPDATE ' . identifier($name) . ' SET ' . implode(', ', $assignments));
        }
        if ($profile === 'business' && isset($columns['kimai2_configuration'])) {
            foreach ($scrubbedConfiguration as $name => $value) {
                $db->exec('UPDATE `kimai2_configuration` SET `value` = ' . $db->quote($value) . ' WHERE `name` = ' . $db->quote($name));
            }
        }
    }
    if ($profile === 'business' && isset($columns['kimai2_configuration'])) {
        foreach ($scrubbedConfiguration as $name => $value) {
            $count = (int) $db->query(
                'SELECT COUNT(*) FROM `kimai2_configuration` WHERE `name` = ' . $db->quote($name)
                . ' AND NOT (`value` <=> ' . $db->quote((string) $value) . ')'
            )->fetchColumn();
            if ($count !== 0) {
                throw new RuntimeException('Credential configuration verification failed for ' . $name);
            }
        }
    }
    foreach ($purge as $name) {
        // Kimai creates new defaults for the dedicated test administrator.
        $where = $verifyAdmin && $name === 'kimai2_user_preferences'
            ? ' WHERE user_id IS NULL OR user_id NOT IN (SELECT id FROM kimai2_users WHERE username = ' . $db->quote((string) getenv('TEST_ADMIN_USERNAME')) . ')' : '';
        if (isset($columns[$name]) && (int) $db->query('SELECT COUNT(*) FROM ' . identifier($name) . $where)->fetchColumn() !== 0) {
            throw new RuntimeException('Sensitive table not empty: ' . $name);
        }
    }
    foreach ($policies as $name => $policy) {
        if (!isset($columns[$name])) {
            continue;
        }
        $actual = array_column($columns[$name], 'Field');
        foreach ($policy['set'] as $column => $value) {
            if (!in_array($column, $actual, true)) {
                continue;
            }
            $where = $verifyAdmin && $name === 'kimai2_users' ? 'username <> ' . $db->quote((string) getenv('TEST_ADMIN_USERNAME')) . ' AND ' : '';
            if ((int) $db->query('SELECT COUNT(*) FROM ' . identifier($name) . ' WHERE ' . $where . 'NOT (' . identifier($column) . ' <=> (' . $value . '))')->fetchColumn() !== 0) {
                throw new RuntimeException('Sanitization verification failed for ' . $name . '.' . $column);
            }
        }
    }
    // A dump can contain orphaned rows while FOREIGN_KEY_CHECKS is disabled.
    $keys = $db->query('SELECT * FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
    $constraints = [];
    foreach ($keys as $key) {
        if ($key['REFERENCED_TABLE_SCHEMA'] !== 'kimai') {
            throw new RuntimeException('Cross-database foreign keys are unsupported.');
        }
        $constraints[$key['TABLE_NAME'] . '/' . $key['CONSTRAINT_NAME']][] = $key;
    }
    foreach ($constraints as $parts) {
        $joins = [];
        $nonnull = [];
        foreach ($parts as $part) {
            $joins[] = 'c.' . identifier($part['COLUMN_NAME']) . ' = p.' . identifier($part['REFERENCED_COLUMN_NAME']);
            $nonnull[] = 'c.' . identifier($part['COLUMN_NAME']) . ' IS NOT NULL';
        }
        $first = $parts[0];
        $sql = 'SELECT COUNT(*) FROM ' . identifier($first['TABLE_NAME']) . ' c LEFT JOIN ' . identifier($first['REFERENCED_TABLE_NAME']) . ' p ON ' . implode(' AND ', $joins)
            . ' WHERE ' . implode(' AND ', $nonnull) . ' AND p.' . identifier($first['REFERENCED_COLUMN_NAME']) . ' IS NULL';
        if ((int) $db->query($sql)->fetchColumn() !== 0) {
            throw new RuntimeException('Restore contains broken foreign keys.');
        }
    }
    if ($verifyAdmin) {
        $admin = $db->query('SELECT * FROM kimai2_users WHERE username = ' . $db->quote((string) getenv('TEST_ADMIN_USERNAME')))->fetch(PDO::FETCH_ASSOC);
        if ($admin === false || $admin['email'] !== getenv('TEST_ADMIN_EMAIL') || (int) $admin['enabled'] !== 1
            || $admin['auth'] !== 'kimai' || !in_array('ROLE_SUPER_ADMIN', json_decode($admin['roles'], true), true)
            || !password_verify(getenv('TEST_ADMIN_PASSWORD'), $admin['password'])) {
            throw new RuntimeException('Dedicated test administrator verification failed.');
        }
    }
    $after = $db->query('SELECT COUNT(*) AS records, COALESCE(SUM(duration), 0) AS seconds FROM kimai2_timesheet')->fetch(PDO::FETCH_ASSOC);
    if ($before !== $after) {
        throw new RuntimeException('Sanitization changed timesheet counts or duration totals.');
    }
    if ($businessBefore !== null && $businessBefore !== businessFingerprints($db, $policies, $columns, array_keys($scrubbedConfiguration))) {
        throw new RuntimeException('Credential removal changed business records.');
    }
    if ($db->inTransaction()) {
        $db->commit();
    }
    echo ucfirst($profile) . ' profile verified: ' . json_encode($after, JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    // PDO errors can contain source values; expose only our own policy failures.
    fwrite(STDERR, ($error instanceof PDOException ? 'Database operation failed during sanitization.' : $error->getMessage()) . "\n");
    exit(1);
}
