<?php

namespace Npostnik\BePermissions\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ExportBegroupsCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->exportBeGroups($output);

        return Command::SUCCESS;
    }

    protected function exportBeGroups($output)
    {
        $targetFolder = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('be_permissions', 'targetFolder');
        if (empty($targetFolder)) {
            $output->errln('targetFolder is empty');
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
            $yaml = Yaml::dump($this->prepareForExport($group), 6);
            file_put_contents($filename, $yaml);
            $output->writeLn(sprintf('The permissions for "%s" are written to: %s', $group['title'], $filename));
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
