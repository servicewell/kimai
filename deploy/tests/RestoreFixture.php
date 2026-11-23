<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require '/opt/kimai/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/kimai/.env');
$kernel = new App\Kernel('prod', false);
$kernel->boot();
$manager = $kernel->getContainer()->get('doctrine')->getManager();
$db = $manager->getConnection();
$adminUsername = getenv('TEST_ADMIN_USERNAME') ?: 'test-local-admin';
if (($argv[1] ?? '') === 'seed') {
    $user = new App\Entity\User();
    // Deliberately collide with another user's final placeholder to test unique updates.
    $user->setUserIdentifier('test-user-2');
    $user->setEmail('user-2@example.invalid');
    $user->setAlias('PRODUCTION_SENTINEL person');
    $user->setPassword(password_hash('PRODUCTION_SENTINEL password', PASSWORD_BCRYPT));
    $user->setEnabled(true);
    $user->setApiToken('PRODUCTION_SENTINEL token');
    $user->setTotpSecret('PRODUCTION_SENTINEL secret');
    $other = new App\Entity\User();
    $other->setUserIdentifier('PRODUCTION_SENTINEL');
    $other->setEmail('original@example.com');
    $other->setPassword(password_hash('PRODUCTION_SENTINEL password', PASSWORD_BCRYPT));
    $other->setEnabled(true);
    $other->setSupervisor($user);
    $customer = new App\Entity\Customer('PRODUCTION_SENTINEL customer');
    $customer->setName('PRODUCTION_SENTINEL customer');
    $customer->setEmail('original@example.com');
    $customer->setCountry('SE');
    $customer->setCurrency('SEK');
    $customer->setTimezone('Europe/Stockholm');
    $customer->setComment('PRODUCTION_SENTINEL customer notes');
    $customer->setAddressLine1('PRODUCTION_SENTINEL address');
    $customer->setBudget(12345);
    $project = new App\Entity\Project();
    $project->setCustomer($customer);
    $project->setName('PRODUCTION_SENTINEL project');
    $project->setOrderNumber('PRODUCTION_SENTINEL order');
    $activity = new App\Entity\Activity();
    $activity->setProject($project);
    $activity->setName('PRODUCTION_SENTINEL activity');
    $tag = new App\Entity\Tag();
    $tag->setName('PRODUCTION_SENTINEL tag');
    $team = new App\Entity\Team('PRODUCTION_SENTINEL team');
    $team->setName('PRODUCTION_SENTINEL team');
    $team->addUser($user);
    $customer->addTeam($team);
    $project->addTeam($team);
    $activity->addTeam($team);
    foreach ([$user, $other, $customer, $project, $activity, $tag, $team] as $entity) {
        $manager->persist($entity);
    }
    foreach ([$user, $other] as $index => $owner) {
        $begin = new DateTime('2026-10-01 09:00:00', new DateTimeZone('Europe/Stockholm'));
        $entry = new App\Entity\Timesheet();
        $entry->setUser($owner);
        $entry->setProject($project);
        $entry->setActivity($activity);
        $entry->setBegin(clone $begin);
        $entry->setEnd((clone $begin)->modify('+' . ($index + 1) . ' hours'));
        $entry->setDuration(($index + 1) * 3600);
        $entry->setDescription('PRODUCTION_SENTINEL private work');
        $entry->setRate(9876);
        $entry->setInternalRate(5432);
        $entry->setHourlyRate(9876);
        $entry->addTag($tag);
        $manager->persist($entry);
    }
    $token = new App\Entity\AccessToken($user, 'PRODUCTION_SENTINEL access token');
    $token->setName('PRODUCTION_SENTINEL token name');
    $manager->persist($token);
    $manager->flush();
    $db->insert('kimai2_configuration', ['name' => 'PRODUCTION_SENTINEL webhook', 'value' => 'https://production.example.com']);
    $db->insert('kimai2_configuration', ['name' => 'saml.activate', 'value' => '1']);
    $db->insert('kimai2_configuration', ['name' => 'ldap.activate', 'value' => '1']);
    $db->insert('kimai2_configuration', ['name' => 'user.login', 'value' => '0']);
    $db->insert('kimai2_sessions', ['id' => 'synthetic-production-session', 'data' => 'PRODUCTION_SENTINEL session', 'time' => time(), 'lifetime' => 3600]);
    $db->insert('kimai2_user_preferences', ['user_id' => $user->getId(), 'name' => 'salary', 'value' => 'PRODUCTION_SENTINEL payroll']);
    $db->insert('kimai2_customers_meta', ['customer_id' => $customer->getId(), 'name' => 'private', 'value' => 'PRODUCTION_SENTINEL metadata', 'visible' => 1]);
    $db->insert('kimai2_bookmarks', ['user_id' => $user->getId(), 'type' => 'timesheet', 'name' => 'PRODUCTION_SENTINEL', 'content' => 'PRODUCTION_SENTINEL private URL']);
    $db->insert('kimai2_customers_comments', ['customer_id' => $customer->getId(), 'created_by_id' => $user->getId(), 'message' => 'PRODUCTION_SENTINEL comment', 'created_at' => '2026-10-01 09:00:00', 'pinned' => 0]);
    $db->insert('kimai2_invoices', [
        'customer_id' => $customer->getId(), 'user_id' => $user->getId(), 'invoice_number' => 'PRODUCTION_SENTINEL',
        'created_at' => '2026-10-01 09:00:00', 'timezone' => 'Europe/Stockholm', 'total' => 9876, 'tax' => 123,
        'currency' => 'SEK', 'status' => 'new', 'due_days' => 30, 'vat' => 25, 'invoice_filename' => 'PRODUCTION_SENTINEL.pdf',
    ]);
    foreach (['activities' => $activity->getId(), 'projects' => $project->getId(), 'customers' => $customer->getId()] as $entity => $owner) {
        $foreignKey = ['activities' => 'activity_id', 'projects' => 'project_id', 'customers' => 'customer_id'][$entity];
        $db->insert('kimai2_' . $entity . '_rates', ['user_id' => $user->getId(), $foreignKey => $owner, 'rate' => 9876, 'fixed' => 0, 'internal_rate' => 5432]);
    }
    echo "Synthetic source fixture created.\n";
    exit;
}

if (($argv[1] ?? '') === 'assert-business') {
    if ($db->fetchOne('SELECT name FROM kimai2_customers LIMIT 1') !== 'PRODUCTION_SENTINEL customer'
        || $db->fetchOne('SELECT email FROM kimai2_customers LIMIT 1') !== 'original@example.com'
        || $db->fetchOne('SELECT order_number FROM kimai2_projects LIMIT 1') !== 'PRODUCTION_SENTINEL order'
        || (float) $db->fetchOne('SELECT SUM(rate) FROM kimai2_timesheet') !== 29628.0
        || (float) $db->fetchOne('SELECT SUM(budget) FROM kimai2_customers') !== 12345.0
        || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_invoices') !== 1
        || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_customers_meta') !== 1
        || (int) $db->fetchOne("SELECT COUNT(*) FROM kimai2_user_preferences WHERE name = 'salary'") !== 1
        || (int) $db->fetchOne("SELECT COUNT(*) FROM kimai2_configuration WHERE name = 'PRODUCTION_SENTINEL webhook'") !== 1
        || $db->fetchOne("SELECT value FROM kimai2_configuration WHERE name = 'saml.activate'") !== '0'
        || $db->fetchOne("SELECT value FROM kimai2_configuration WHERE name = 'ldap.activate'") !== '0'
        || $db->fetchOne("SELECT value FROM kimai2_configuration WHERE name = 'user.login'") !== '1'
        || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_users WHERE enabled = 1') !== 2
        || (int) $db->fetchOne("SELECT COUNT(*) FROM kimai2_users WHERE password <> '' OR api_token IS NOT NULL OR totp_secret IS NOT NULL") !== 0
        || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_access_token') !== 0
        || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_sessions') !== 0) {
        throw new RuntimeException('Business fixture values or credential removal are incorrect.');
    }
    echo "Business fixture verified: original identities, enabled flags, references, amounts and metadata; credentials removed.\n";
    exit;
}

foreach ($db->fetchFirstColumn('SHOW TABLES') as $table) {
    foreach ($db->fetchAllAssociative('SHOW COLUMNS FROM `' . $table . '`') as $column) {
        if (preg_match('/char|text|blob|json/', $column['Type']) !== 1) {
            continue;
        }
        if ((int) $db->fetchOne('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $column['Field'] . '` LIKE ?', ['%PRODUCTION_SENTINEL%']) !== 0) {
            throw new RuntimeException('Source fixture text survived sanitization.');
        }
    }
}
if ((int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_timesheet') !== 2 || (int) $db->fetchOne('SELECT SUM(duration) FROM kimai2_timesheet') !== 10800
    || (int) $db->fetchOne("SELECT COUNT(*) FROM kimai2_timesheet WHERE date_tz = '2026-10-01'") !== 2
    || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_timesheet t JOIN kimai2_users u ON u.id = t.user JOIN kimai2_projects p ON p.id = t.project_id JOIN kimai2_customers c ON c.id = p.customer_id JOIN kimai2_activities a ON a.id = t.activity_id') !== 2
    || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_timesheet_tags') !== 2
    || (int) $db->fetchOne('SELECT COUNT(*) FROM kimai2_users WHERE username <> ' . $db->quote($adminUsername) . " AND (enabled <> 0 OR password <> '' OR api_token IS NOT NULL OR totp_secret IS NOT NULL)") !== 0
    || (float) $db->fetchOne('SELECT SUM(rate) FROM kimai2_timesheet') !== 300.0
    || (float) $db->fetchOne('SELECT SUM(budget) FROM kimai2_customers') !== 0.0) {
    throw new RuntimeException('Restored fixture relationships, duration, credentials or synthetic rates are incorrect.');
}
echo "Fixture verified: two timesheets, three hours, preserved links, sanitized text and credentials.\n";
