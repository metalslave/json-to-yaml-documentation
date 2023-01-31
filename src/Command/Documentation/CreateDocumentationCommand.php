<?php

declare(strict_types=1);

namespace App\Command\Documentation;

use App\Command\AbstractBaseCommand;
use App\Request\RequestHelper;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

#[AsCommand(name: 'app:doc:create', description: 'Create yaml file documentation from json file response')]
final class CreateDocumentationCommand extends AbstractBaseCommand
{
    private const RESOURCE_FILE_PATH = '/app/documentation/resource/';
    private const RESULT_FILE_PATH = '/app/documentation/result/';
    private const ONE_TAB = '  '; // 2 spaces
    private const DATE_TIME_REGEXP = '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\+[0-9]{2}:[0-9]{2}$/';
    private const UUID_REGEXP = '/'.RequestHelper::UUID_REGEX.'/';

    private const REGEXP_SCHEMAS = [
        self::DATE_TIME_REGEXP => '#/components/schemas/DateTimeField',
        self::UUID_REGEXP => '#/components/schemas/UuidField',
    ];

    private const CONST_FIELD_NAMES = [
        'editVersion' => '#/components/schemas/EditVersionField',
        'createdBy' => '#/components/schemas/Blameable',
        'updatedBy' => '#/components/schemas/Blameable',
    ];

    private const CONST_OBJECT_NAMES = [
        '.amount.currency' => '#/components/schemas/MoneyField',
    ];

    private string $tab = '';
    private int $tabsCount = 0;
    private string $fieldName = '';

    protected function configure(): void
    {
        parent::configure();

        $this
            ->addOption('json-file', 'j', InputOption::VALUE_REQUIRED, 'Json filename')
            ->addOption('yml-file', 'y', InputOption::VALUE_OPTIONAL, 'Yaml filename')
            ->addOption('field-name', 'f', InputOption::VALUE_OPTIONAL, 'Field name', 'ObjectField')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Create documentation...');

        $jsonFilename = $input->getOption('json-file');
        $yamlFilename = $input->getOption('yml-file');
        $this->fieldName = $input->getOption('field-name');

        if (!\is_string($jsonFilename)) {
            throw new BadRequestHttpException('json filename required');
        }

        if (!\is_string($yamlFilename)) {
            $pathParts = pathinfo($jsonFilename);
            $yamlFilename = !empty($pathParts['filename']) ? $pathParts['filename'] : 'doc-'.(new \DateTime())->format('Y-m-d-H-i-s');
            $yamlFilename .= '.yaml';
        }

        if (empty($this->fieldName)) {
            $this->fieldName = 'ObjectField';
        }

        $this->createDocumentation($jsonFilename, $yamlFilename);

        $io->writeln('json file: '.$jsonFilename);
        $io->writeln('yaml file: '.$yamlFilename);

        $io->success('DONE');

        return self::SUCCESS;
    }

    private function createDocumentation(string $jsonFilename, string $yamlFilename): void
    {
        $filePath = self::RESOURCE_FILE_PATH.$jsonFilename;
        $jsonFileContent = file_get_contents($filePath);

        if (!\is_string($jsonFileContent)) {
            throw new BadRequestHttpException(sprintf('Can not get content from file %s', $filePath));
        }

        $arrayData = json_decode($jsonFileContent, true, 512, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (!\is_array($arrayData)) {
            throw new BadRequestHttpException('Can not get array from json');
        }

        $yamlContent = $this->addObjectField($this->fieldName, $arrayData);

        file_put_contents(self::RESULT_FILE_PATH.$yamlFilename, $yamlContent);
    }

    private function addObjectField(?string $objectName, array $arrayData): string
    {
        $saveTabs = $this->tabsCount;
        $content = '';

        if (\is_string($objectName)) {
            $content = $this->addLine($objectName.':');
        }

        $constContent = $this->checkObjectConstName($arrayData);
        if (\is_string($constContent)) {
            $content .= $constContent;
        } else {
            $this->addTabs();
            $content .= $this->addLine('type: object');
            $content .= $this->addRequired($arrayData);
            $content .= $this->addProperties($arrayData);
        }

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addRequired(array $arrayData): string
    {
        if (empty($arrayData)) {
            return '';
        }

        $saveTabs = $this->tabsCount;
        $content = $this->addLine('required:');

        $this->addTabs();

        foreach ($arrayData as $field => $data) {
            $content .= $this->addLine('- '.$field);
        }

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addProperties(array $arrayData): string
    {
        if (empty($arrayData)) {
            return '';
        }

        $saveTabs = $this->tabsCount;
        $content = $this->addLine('properties:');
        $this->addTabs();

        foreach ($arrayData as $field => $value) {
            $checkConstNameResult = $this->checkFieldConstName($field);
            if (\is_string($checkConstNameResult)) {
                $content .= $checkConstNameResult;
                continue;
            }

            switch (\gettype($value)) {
                case 'string':
                    $content .= $this->addStringField($field, $value);
                    break;
                case 'array':
                    if (isset($value[0])) {
                        if (\is_array($value[0])) {
                            $content .= $this->addArrayField($field, $value[0]);
                        } else {
                            $content .= $this->addArrayOfStringsField($field, $value[0]);
                        }
                    } elseif (empty($value)) {
                        $content .= $this->addArrayField($field, $value);
                    } else {
                        $content .= $this->addObjectField($field, $value);
                    }
                    break;
                case 'integer':
                    $content .= $this->addIntegerField($field, $value);
                    break;
                case 'double':
                    $content .= $this->addFloatField($field, $value);
                    break;
                case 'boolean':
                    $content .= $this->addBooleanField($field);
                    break;
                case 'NULL':
                    $content .= $this->addNullField($field);
                    break;
            }
        }

        $this->setTabs($saveTabs);

        return $content;
    }

    private function checkFieldConstName(string $field): ?string
    {
        $saveTabs = $this->tabsCount;
        $content = null;

        foreach (self::CONST_FIELD_NAMES as $fieldName => $schema) {
            if ($fieldName === $field) {
                $content = $this->addLine($field.':');
                $this->addTabs();
                $content .= $this->addLine('$ref: \''.$schema.'\'');
                break;
            }
        }

        $this->setTabs($saveTabs);

        return $content;
    }

    private function checkObjectConstName(array $arrayData): ?string
    {
        $saveTabs = $this->tabsCount;
        $content = null;

        $fields = '';

        foreach ($arrayData as $fieldName => $value) {
            $fields .= '.'.$fieldName;
        }

        foreach (self::CONST_OBJECT_NAMES as $fieldName => $schema) {
            if ($fieldName === $fields) {
                $this->addTabs();
                $content .= $this->addLine('$ref: \''.$schema.'\'');
                break;
            }
        }

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addNullField(string $objectName): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($objectName.':');
        $this->addTabs();

        $content .= $this->addLine('type: null');
        $content .= $this->addLine('nullable: true');

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addBooleanField(string $objectName): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($objectName.':');

        $this->addTabs();

        $content .= $this->addLine('type: boolean');

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addFloatField(string $objectName, float $data): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($objectName.':');

        $this->addTabs();

        $content .= $this->addLine('type: number');
        $content .= $this->addLine('format: float');
        $content .= $this->addLine('example: \''.$data.'\'');

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addIntegerField(string $objectName, int $data): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($objectName.':');

        $this->addTabs();

        $content .= $this->addLine('type: integer');
        $content .= $this->addLine('example: \''.$data.'\'');

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addArrayField(string $objectName, array $arrayData): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($objectName.':');
        $this->addTabs();

        $content .= $this->addLine('type: array');
        $content .= $this->addLine('items:');

        $content .= $this->addObjectField(null, $arrayData);

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addArrayOfStringsField(string $field, string $value): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($field.':');
        $this->addTabs();

        $content .= $this->addLine('type: array');
        $content .= $this->addLine('items:');
        $this->addTabs();
        $content .= $this->addLine('type: string');
        $content .= $this->addLine('example: \''.$value.'\'');

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addStringField(string $field, string $value): string
    {
        $saveTabs = $this->tabsCount;
        $content = $this->addLine($field.':');
        $this->addTabs();

        $isPattern = false;

        foreach (self::REGEXP_SCHEMAS as $pattern => $schema) {
            if (preg_match($pattern, $value)) {
                $isPattern = true;
                $content .= $this->addLine('$ref: \''.$schema.'\'');
            }
        }

        if (!$isPattern) {
            $content .= $this->addLine('type: string');
            $content .= $this->addLine('example: \''.$value.'\'');
        }

        $this->setTabs($saveTabs);

        return $content;
    }

    private function addLine(string $stringLine): string
    {
        return $this->tab.$stringLine.\PHP_EOL;
    }

    private function setTabs(int $count): void
    {
        $this->tabsCount = $count;
        $this->tab = str_repeat(self::ONE_TAB, $this->tabsCount);
    }

    private function addTabs(int $count = 1): void
    {
        $this->tabsCount += $count;
        $this->setTabs($this->tabsCount);
    }
}
