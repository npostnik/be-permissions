<?php

namespace Npostnik\BePermissions\Command;

use Doctrine\DBAL\Exception;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ExportBegroupsCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output):int
    {
        $this->exportBeGroups($output);

        return Command::SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @return int|null
     * @throws Exception
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    protected function exportBeGroups($output)
    {
        $targetFolder = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('be_permissions', 'targetFolder');
        if (empty($targetFolder)) {
            $output->writeLn('targetFolder is empty');
            return Command::FAILURE;
        }

        $targetFolder = GeneralUtility::getFileAbsFileName($targetFolder);

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_groups');

        $groups = $queryBuilder
            ->select('*')
            ->from('be_groups')
            ->executeQuery()
            ->fetchAllAssociative();

        $output->writeLn("output: " . $targetFolder);
        foreach ($groups as $group) {
            $title = $this->normalizeTitle($group['title']);
            $filename = $targetFolder . $group['uid'] . '-' . $title . '.yaml';
            $this->renameExistingFile($targetFolder, (int)$group['uid'], $filename, $output);
            $yaml = Yaml::dump($this->prepareForExport($group), 6);
            file_put_contents($filename, $yaml);
            $output->writeLn(sprintf('The permissions for "%s" are written to: %s', $group['title'], $filename));
        }
        return null;
    }

    /**
     * Renames an already exported file with the same uid prefix (e.g. after a title change)
     * to the new filename, so that it is overwritten instead of duplicated.
     */
    protected function renameExistingFile(string $targetFolder, int $uid, string $newFilename, OutputInterface $output): void
    {
        // The trailing dash prevents uid 1 from matching "10-*.yaml"
        $existingFiles = glob($targetFolder . $uid . '-*.yaml') ?: [];
        $existingFiles = array_values(array_diff($existingFiles, [$newFilename]));
        if ($existingFiles === []) {
            return;
        }

        if (file_exists($newFilename)) {
            $output->writeLn(sprintf('<comment>Skipped renaming, target already exists: %s</comment>', $newFilename));
            return;
        }

        $oldFilename = array_shift($existingFiles);
        rename($oldFilename, $newFilename);
        $output->writeLn(sprintf('Renamed %s to %s', basename($oldFilename), basename($newFilename)));

        foreach ($existingFiles as $duplicate) {
            $output->writeLn(sprintf('<comment>Further file with the same id left untouched: %s</comment>', basename($duplicate)));
        }
    }

    protected function normalizeTitle($title)
    {
        // Replace spaces with dashes
        $title = str_replace(' ', '-', $title);
        // Remove all characters except letters, numbers, and dashes
        $title = preg_replace('/[^a-zA-Z0-9\-]/', '', $title);
        // Replace double dashes with a single dash
        $title = str_replace('--', '-', $title);
        // Convert to lowercase
        $title = strtolower($title);
        return $title;
    }

    protected function prepareForExport($group)
    {
        $export = [];
        $excludeKeys = ['crdate', 'tstamp'];
        $explodeFields = [
            'file_permissions',
            'non_exclude_fields',
            'explicit_allowdeny',
            'pagetypes_select',
            'tables_select',
            'tables_modify',
            'groupMods',
            'subgroup',
            'availableWidgets'
        ];
        foreach($group as $key => $value) {
            if(in_array($key, $excludeKeys)) {
                continue;
            }
            if(empty($value) && $key !== 'pid') {
                continue;
            }
            if(in_array($key, $explodeFields)) {
                $export[$key] = GeneralUtility::trimExplode(',', $value);
            } else {
                $export[$key] = $value;
            }
        }
        return $export;
    }

}
