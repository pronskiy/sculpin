<?php

declare(strict_types=1);

/*
 * This file is a part of Sculpin.
 *
 * (c) Dragonfly Development Inc.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sculpin\Core\Source;

use League\MimeTypeDetection\MimeTypeDetector;
use Symfony\Component\Finder\SplFileInfo;
use Dflydev\DotAccessConfiguration\Configuration as Data;
use Dflydev\DotAccessConfiguration\YamlConfigurationBuilder as YamlDataBuilder;

/**
 * @author Beau Simensen <beau@dflydev.com>
 */
final class FileSource extends AbstractSource
{
    /**
     * @var MimeTypeDetector
     */
    private $detector;

    public function __construct(
        MimeTypeDetector $detector,
        DataSourceInterface $dataSource,
        SplFileInfo $file,
        bool $isRaw,
        bool $hasChanged = false
    ) {
        $this->detector = $detector;
        $this->sourceId = 'FileSource:'.$dataSource->dataSourceId().':'.$file->getRelativePathname();
        $this->relativePathname = $file->getRelativePathname();
        $this->filename = $file->getFilename();
        $this->file = $file;
        $this->isRaw = $isRaw;
        $this->hasChanged = $hasChanged;

        $this->init();
    }

    /**
     * Initialize source
     *
     * @param bool $hasChanged Has the file changed?
     */
    protected function init(bool $hasChanged = false): void
    {
        parent::init($hasChanged);

        $originalData = $this->data;

        if ($this->isRaw) {
            $this->useFileReference = true;
            $this->data = new Data;
        } else {
            $internetMediaType = $this->detector->detectMimeType(
                $this->file->getRealPath(),
                $this->file->getContents()
            );

            if ($internetMediaType &&
                (str_starts_with($internetMediaType, 'text/') ||
                $internetMediaType === 'application/xml')) {
                // Only text files can be processed by Sculpin and since we
                // have to read them here we are going to ensure that we use
                // the content we read here instead of having someone else
                // read the file again later.
                $this->useFileReference = false;

                // Additionally, any text file is a candidate for formatting.
                $this->canBeFormatted = true;

                $content = $this->file->getContents();

                if (preg_match('/^\s*(?:---[\s]*[\r\n]+)(.*?)(?:---[\s]*[\r\n]+)(.*?)$/s', $content, $matches)) {
                    $this->content = $matches[2];
                    if (preg_match('/^(\s*[-]+\s*|\s*)$/', $matches[1])) {
                        // There is nothing useful in the YAML front matter.
                        $this->data = new Data;
                    } else {
                        // There may be YAML frontmatter
                        try {
                            $builder = new YamlDataBuilder($matches[1]);
                            $this->data = $builder->build();
                        } catch (\InvalidArgumentException $e) {
                            // Likely not actually YAML front matter available,
                            // treat the entire file as pure content.
                            echo ' ! ' . $this->sourceId() . ' ' . $e->getMessage() . ' !' . PHP_EOL;
                            $this->content = $content;
                            $this->data = new Data;
                        }
                    }
                } else {
                    $this->content = $content;
                    $this->data = new Data;
                    $this->canBeFormatted = false;
                }
            } else {
                $this->useFileReference = true;
                $this->data = new Data;
            }
        }

        if ($this->data->get('date')) {
            if (! is_numeric($this->data->get('date'))) {
                $this->data->set('date', strtotime($this->data->get('date')));
            }

            $this->data->set('calculated_date', $this->data->get('date'));
        }

        if ($originalData) {
            $this->data->import($originalData, false);
        }
    }
}
