<?php


namespace App\Service;

/**
 * Class TextFileExtractor
 * @package App\Service
 */
class TextFileExtractor
{
    /**
     * @param string $filepath
     * @return array
     */
    public function getData(string $filepath): array
    {
        $data = explode(PHP_EOL, file_get_contents($filepath));
        $terms = [];
        foreach ($data as $term) {
            $terms[] = trim($term);
        }
        return $terms;
    }
}