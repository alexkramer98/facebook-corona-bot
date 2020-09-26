<?php

namespace App\Command;

use App\Service\Logger;
use App\Service\TextFileExtractor;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Panther\Client;

class RunCommand extends Command
{
    /**
     * @var Client
     */
    private $client;
    /**
     * @var array
     */
    private $pagesToCrawl;
    /**
     * @var array
     */
    private $postSearchTerms;
    /**
     * @var array
     */
    private $commentSearchTerms;
    /**
     * @var Logger
     */
    private $logger;

    public function __construct(TextFileExtractor $textFileExtractor, string $name = null)
    {
        parent::__construct($name);
        $this->client = Client::createChromeClient('./chromedriver', [
            '--window-size=1400,1000',
            '--disable-notifications'
        ]);
        $this->pagesToCrawl = $textFileExtractor->getData('config/app/pages-to-crawl.txt');
        $this->postSearchTerms = $textFileExtractor->getData('config/app/post-terms.txt');
        $this->commentSearchTerms = $textFileExtractor->getData('config/app/comment-terms.txt');
        $this->logger = new Logger('log/log.txt', true, true);
    }

    protected static $defaultName = 'app:run';

    public function getFacebookCredentials(): array
    {
        return [
            'user' => $_ENV['FB_USER'],
            'pass' => $_ENV['FB_PASS'],
        ];
    }

    private function login(array $credentials): void
    {
        $this->client->request('GET', 'https://facebook.com');
        $this->client->submitForm(
            'Aanmelden', [
                'email' => $credentials['user'],
                'pass' => $credentials['pass']
            ]
        );
        $this->logger->log('Logged in', 'Success');
    }

    private function searchForPage(string $page): void
    {
        $this->client->request('GET', 'https://facebook.com');
        sleep(5);
        $searchSelector = 'input[name=q]';
        try {
            $this->client->waitFor($searchSelector);
        } catch (\Exception $e) {
            $this->logger->log(sprintf('Unable to locate the search bar for page "%s". Retrying', $page), 'Error');
            $this->searchForPage($page);
        }
        $this->client->findElement(
            WebDriverBy::cssSelector($searchSelector)
        )->sendKeys($page . PHP_EOL);
    }

    private function findPostsMatchingTerms(string $page, array $terms): array
    {
        $this->searchForPage($page);
        $pageLinkSelector = 'img[alt="' . $page . '"][width="72"][height="72"]';
        try {
            $this->client->waitFor($pageLinkSelector);
        } catch (\Exception $e) {
            $this->logger->log(sprintf('Unable to locate the page image for page "%s". Retrying', $page), 'Error');
            $this->findPostsMatchingTerms($page, $terms);
        }
        $this->client
            ->findElement(
                WebDriverBy::cssSelector($pageLinkSelector)
            )->click()
        ;
        $postsLinkSelector = 'div[data-key="tab_posts"]';
        try {
            $this->client->waitFor($postsLinkSelector);
        } catch (\Exception $e) {
            $this->logger->log(sprintf('Unable to locate the messages tab for page "%s". Retrying', $page), 'Error');
            $this->findPostsMatchingTerms($page, $terms);
        }
        $this->client
            ->findElement(
                WebDriverBy::cssSelector($postsLinkSelector)
            )->click()
        ;
        $postsSelector = '#pagelet_timeline_main_column > div:first-child > div:nth-child(2) > div:first-child > div';
        try {
            $this->client->waitFor($postsSelector);
        } catch (\Exception $e) {
            $this->logger->log(sprintf('Unable to locate the main posts div for page "%s". Retrying', $page), 'Critical');
            $this->findPostsMatchingTerms($page, $terms);
        }
        $posts = $this->client
            ->findElements(
                WebDriverBy::cssSelector($postsSelector)
        );
        $posts = array_filter($posts, function($post) use ($terms) {
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
            foreach ($terms as $term) {
                if (str_contains(strtolower($text), strtolower($term))) {
                    return true;
                }
            }
            return false;
        });

        $this->logger->log(sprintf('Found %s posts matching terms for page "%s"', count($posts), $page), 'Info');

        return array_values($posts);
    }

    private function findCommentsMatchingTerms(RemoteWebElement $post): array
    {
        try {
            $commentDropdownMenu = $post
                ->findElement(
                    WebDriverBy::cssSelector('a[data-ordering="RANKED_THREADED"]')
                )
            ;
            $commentDropdownMenu->click();
        } catch (\Exception $exception) {
            return [];
        }
        $this->client
            ->findElement(
                WebDriverBy::cssSelector('ul[role="menu"] > li:nth-child(3) > a:first-child')
        )->click();

        sleep(5);

        while (true) {
            try {
                $post
                    ->findElement(
                        WebDriverBy::partialLinkText('opmerkingen weergeven')
                    )->click()
                ;
                sleep(3);
            } catch (\Exception $exception) {
                break;
            }
        }
        $comments = $post->findElements(
            WebDriverBy::cssSelector('div[aria-label="Opmerking"]'
            )
        );
        $terms = implode('|', $this->commentSearchTerms);
        $comments = array_filter($comments, function($comment) use ($terms) {
            return preg_match('('.$terms.')', $comment->getText()) === 1;
        });
        $this->logger->log(sprintf('Found %s matching comments', count($comments)), 'Info');
        return $comments;
    }

    private function placeCommentIfNotExists(RemoteWebElement $comment): void
    {
        dump($comment);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->logger->log('Initiated', 'Info');
        $this->login($this->getFacebookCredentials());
        foreach ($this->pagesToCrawl as $page) {
            $posts = $this->findPostsMatchingTerms($page, $this->postSearchTerms);
            foreach ($posts as $key => $post) {
                $this->logger->log('Processing post ' . $key, 'Info');
                $comments = $this->findCommentsMatchingTerms($post);
                foreach($comments as $comment) {
                    $this->placeCommentIfNotExists($comment);
                }
            }
        }

        die();
        return 0;
    }
}
