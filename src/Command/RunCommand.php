<?php

namespace App\Command;

use App\Service\Logger;
use App\Service\TextFileExtractor;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverKeys;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Panther\Client;

/**
 * Class RunCommand
 * @package App\Command
 */
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
     * @var string
     */
    private $answerBase;
    /**
     * @var Logger
     */
    private $logger;

    /**
     * RunCommand constructor.
     * @param TextFileExtractor $textFileExtractor
     * @param string|null $name
     */
    public function __construct(TextFileExtractor $textFileExtractor, string $name = null)
    {
        parent::__construct($name);
        $this->client = Client::createChromeClient('./chromedriver', [
            '--window-size=1200,1000',
            '--disable-notifications'
        ]);
        $this->pagesToCrawl = $textFileExtractor->getData('config/app/pages-to-crawl.txt');
        $this->postSearchTerms = $textFileExtractor->getData('config/app/post-terms.txt');
        $commentSearchTerms = $textFileExtractor->getData('config/app/comment-terms.txt');

        foreach($commentSearchTerms as $term) {
            $explode = explode(':', $term);
            $this->commentSearchTerms[$explode[0]] = $explode[1];
        }

        $this->answerBase = str_replace(PHP_EOL, WebDriverKeys::SHIFT . WebDriverKeys::ENTER . WebDriverKeys::SHIFT, file_get_contents(
            'config/app/answer-base.txt'
        ));
        $this->logger = new Logger('log/log.txt', true, true);
    }

    protected static $defaultName = 'app:run';

    /**
     * @return array
     */
    public function getFacebookCredentials(): array
    {
        return [
            'user' => $_ENV['FB_USER'],
            'pass' => $_ENV['FB_PASS'],
        ];
    }

    /**
     * @param array $credentials
     */
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

    /**
     * @param string $page
     */
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

    /**
     * @param string $page
     * @param array $terms
     * @return array
     */
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

    /**
     * @param RemoteWebElement $post
     * @return array
     */
    private function findCommentsMatchingTerms(RemoteWebElement $post): array
    {
        try {
            $commentDropdownMenu = $post
                ->findElement(
                    WebDriverBy::cssSelector('a[data-ordering="RANKED_THREADED"]')
                )
            ;
            $commentDropdownMenu->click();
        } catch (NoSuchElementException $exception) {
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
            WebDriverBy::cssSelector('div[aria-label="Opmerking"]')
        );
        $terms = implode('|', array_keys($this->commentSearchTerms));
        $comments = array_filter($comments, function($comment) use ($terms) {
            return preg_match('('.$terms.')', $comment->getText()) === 1;
        });
        $this->logger->log(sprintf('Found %s matching comments', count($comments)), 'Info');
        return $comments;
    }

    /**
     * @param RemoteWebElement $comment
     */
    private function placeCommentIfNotExists(RemoteWebElement $comment): void
    {
        $subCommentsDiv = $comment->findElement(
            WebDriverBy::xpath('../../div[2]')
        );
        try {
            $subCommentsLink = $subCommentsDiv->findElement(
                WebDriverBy::partialLinkText(' antwoord')
            );
            $this->scrollIntoView($subCommentsLink);
            $subCommentsLink->click();

            sleep(5);

            while (true) {
                try {
                    $loadMoreCommentsLink = $subCommentsDiv
                        ->findElement(
                            WebDriverBy::partialLinkText(' antwoorden weergeven')
                        )
                    ;
                    $loadMoreCommentsLink->click();
                    sleep(3);
                } catch (NoSuchElementException $exception) {
                    break;
                }
            }

            if (str_contains($subCommentsDiv->getText(), $_ENV['FB_ACCOUNT_NAME'])) {
                $this->logger->log('This comment has already been answered by bot. Skipping', 'Info');
                return;
            }
            $this->placeAnswer($subCommentsDiv, $comment->getText());
            return;
        } catch (NoSuchElementException $exception) {
            sleep(5);

            $answerButton = $comment
                ->findElement(
                    WebDriverBy::linkText('Beantwoorden')
                )
            ;

            $this->scrollIntoView($answerButton);
            $answerButton->click();

            sleep(5);

            $this->placeAnswer($subCommentsDiv, $comment->getText());
        }
    }

    /**
     * @param RemoteWebElement $element
     */
    private function scrollIntoView(RemoteWebElement  $element): void
    {
        $yPosition = $element
            ->getCoordinates()
            ->onPage()
            ->getY()
        ;
        $this->client
            ->executeScript('window.scroll(0, '.($yPosition-200).')')
        ;
        sleep(5);
    }

    private function getMatchingTerms(string $comment, array $terms): array
    {
        $matchingTerms = [];
        foreach ($terms as $term) {
            if (str_contains(strtolower($comment), strtolower($term))) {
                $matchingTerms[] = $term;
            }
        }
        return array_unique($matchingTerms);
    }

    /**
     * @param RemoteWebElement $subCommentsDiv
     * @param string $commentText
     */
    private function placeAnswer(RemoteWebElement $subCommentsDiv, string $commentText): void
    {
        $commentTextBox = $subCommentsDiv
            ->findElement(
                WebDriverBy::cssSelector('div[role="textbox"]')
        );
        $this->scrollIntoView($commentTextBox);
        $commentTextBox->click();

        $matchingTerms = $this->getMatchingTerms($commentText, array_keys($this->commentSearchTerms));
        $debunks = [];
        foreach ($matchingTerms as $term) {
            $debunks[] = ucfirst($term) . ': ' . $this->commentSearchTerms[$term] . WebDriverKeys::SHIFT . WebDriverKeys::ENTER . WebDriverKeys::SHIFT;
        }
        $answer = str_replace('{matchingTerms}', implode(', ', $matchingTerms), $this->answerBase);
        $answer = str_replace('{debunks}', implode(WebDriverKeys::SHIFT . WebDriverKeys::ENTER . WebDriverKeys::SHIFT, $debunks), $answer);

        $commentTextBox->sendKeys($answer . PHP_EOL);
        $this->logger->log('Placed answer!! Sleeping for 2 minutes to try to avoid rate limiting', 'Success');
        sleep(120);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        while (true) {
            $this->logger->log('Initiated', 'Info');
            $this->login($this->getFacebookCredentials());
            foreach ($this->pagesToCrawl as $index => $page) {
                $posts = $this->findPostsMatchingTerms($page, $this->postSearchTerms);
                foreach ($posts as $key => $post) {
                    $this->logger->log('Processing post ' . $key, 'Info');
                    $comments = $this->findCommentsMatchingTerms($post);
                    foreach($comments as $comment) {
                        $this->placeCommentIfNotExists($comment);
                    }
                }
            }
            $this->logger->log('Cycle complete! Going to sleep for 30 minutes. Good night.', 'Info');
            sleep(30 * 60);
        }
    }
}
