<?php

namespace App\Command;

use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Panther\Client;

class RunCommand extends Command
{
    const TERMS_CORONA = [
        'corona',
        'covid',
        'virus',
        'virussen',
        'viroloog',
        'virologen'
    ];

    const TERMS_STUPID_COMMENTS = [
        'pinokio',
        'pinokkio',
        'viruswaanzin',
        'virus waanzin',
        'schapen',
        'complot',
        'pcr test',
        'pcr-test',
        'fake news',
        'fake nieuws',
        'petitie',
        'word wakker',
        'agenda 30',
        'geheime agenda',
        'waarheid',
        'bedonderd',
        'ikdoenietmeermee',
        'onzin',
        'voor de gek gehouden',
        'de waarheid',
        'gif',
        'wake up',
        'oogkleppen'
    ];

    protected static $defaultName = 'app:run';

    protected function configure()
    {

    }

    public function getClient(): Client
    {
        return Client::createChromeClient('./chromedriver', [
            '--window-size=1400,1000',
            '--disable-notifications'
        ]);
    }

    public function getFacebookCredentials(): array
    {
        return [
            'user' => $_ENV['FB_USER'],
            'pass' => $_ENV['FB_PASS'],
        ];
    }

    public function login(Client $client, array $credentials): void
    {
        $client->request('GET', 'https://facebook.com');
        $client->submitForm(
            'Aanmelden', [
                'email' => $credentials['user'],
                'pass' => $credentials['pass']
            ]
        );
    }

    public function findCoronaRelatedMessages(Client $client, string $page): array
    {
        $client->findElement(
            WebDriverBy::cssSelector('input[placeholder=Zoeken]')
        )->sendKeys($page . "\n");

        sleep(5);

        $client
            ->findElement(
                WebDriverBy::linkText($page)
            )->click()
        ;

        sleep(5);

        $client
            ->findElement(
                WebDriverBy::linkText('Berichten')
            )->click()
        ;

        sleep(5);

        $posts = $client
            ->findElements(
                WebDriverBy::cssSelector('#pagelet_timeline_main_column > div:first-child > div:nth-child(2) > div:first-child > div')
        );

        $posts = array_filter($posts, function($post) {
            $class = $post->getAttribute('class');
            if (!$class) {
                return false;
            }
            if (str_contains($class, 'clearfix')) {
                return false;
            }
            $postSelector = 'div:first-child > div:first-child > div:first-child > div:nth-child(2) > div:first-child > div:nth-child(3)';
            $text = $post
                ->findElement(
                    WebDriverBy::cssSelector($postSelector)
                )->getText()
            ;
            foreach (self::TERMS_CORONA as $term) {
                if (str_contains(strtolower($text), strtolower($term))) {
                    return true;
                }
            }
            return false;
        });
        return $posts;
    }

    public function findStupidComments(RemoteWebElement $post): array
    {

    }

    public function placeCommentIfNotExists(RemoteWebElement $post, RemoteWebElement $stupidComment): void
    {

    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $client = $this->getClient();
        $credentials = $this->getFacebookCredentials();
        $this->login($client, $credentials);
        $posts = $this->findCoronaRelatedMessages($client, 'RTL Nieuws');
        foreach ($posts as $post) {
            $stupidComments = $this->findStupidComments($post);
            foreach ($stupidComments as $stupidComment) {
                $this->placeCommentIfNotExists($post, $stupidComment);
            }
        }
        return 0;
    }
}
