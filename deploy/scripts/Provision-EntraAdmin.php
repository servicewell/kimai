<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

require '/opt/kimai/vendor/autoload.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/opt/kimai/.env');
$email = $argv[1] ?? '';
if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    throw new InvalidArgumentException('Provide the Entra administrator email as the first argument.');
}

$kernel = new App\Kernel('prod', false);
$kernel->boot();
$application = new Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
$application->setAutoExit(false);
$result = $application->run(new Symfony\Component\Console\Input\ArrayInput([
    'command' => 'kimai:user:create',
    '--ignore-existing' => true,
    'username' => $email,
    'email' => $email,
    'role' => App\Entity\User::ROLE_SUPER_ADMIN,
    'password' => bin2hex(random_bytes(24)),
]));
if ($result !== 0) {
    exit($result);
}

$manager = $kernel->getContainer()->get('doctrine')->getManager();
$user = $manager->getRepository(App\Entity\User::class)->findOneBy(['email' => $email]);
if ($user === null) {
    throw new RuntimeException('The Kimai account could not be found.');
}
$user->setUserIdentifier($email);
$user->setAuth(App\Entity\User::AUTH_SAML);
$user->setPassword('');
$user->addRole(App\Entity\User::ROLE_SUPER_ADMIN);
$manager->persist($user);
$manager->flush();
$manager->refresh($user);
if (!$user->isSamlUser() || !$user->isSuperAdmin()) {
    throw new RuntimeException('The Entra administrator settings could not be verified.');
}
echo "Entra administrator ready: {$email}\n";
