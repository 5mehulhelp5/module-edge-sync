<?php
declare(strict_types=1);

namespace MageCopilot\EdgeSync\Console\Command;

use MageCopilot\EdgeSync\Service\SkuCharsetPolicy;
use Magento\Framework\App\ResourceConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command to audit catalog product SKUs charset and generate optimal Zod regex constraint.
 */
class SkuCharsetReportCommand extends Command
{
    private readonly SkuCharsetPolicy $skuCharsetPolicy;

    /**
     * @param ResourceConnection $resourceConnection
     * @param SkuCharsetPolicy|null $skuCharsetPolicy
     * @param string|null $name
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection,
        ?SkuCharsetPolicy $skuCharsetPolicy = null,
        ?string $name = null
    ) {
        parent::__construct($name);
        $this->skuCharsetPolicy = $skuCharsetPolicy ?? new SkuCharsetPolicy();
    }

    /**
     * @inheritdoc
     */
    protected function configure(): void
    {
        $this->setName('magecopilot:edgesync:sku-charset-report')
            ->setDescription('Audit catalog product SKUs charset and generate optimal Zod regex constraint');
    }

    /**
     * Execute SKU charset inspection.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<info>Auditing catalog product SKUs charset...</info>');

        $connection = $this->resourceConnection->getConnection();
        $tableName = $this->resourceConnection->getTableName('catalog_product_entity');

        $select = $connection->select()
            ->from($tableName, ['sku'])
            ->where('sku IS NOT NULL');

        $rows = $connection->fetchAll($select);
        $skus = array_map(static fn (array $row): string => (string) ($row['sku'] ?? ''), $rows);
        $analysis = $this->skuCharsetPolicy->analyzeBatch($skus);
        $totalSkus = $analysis['total_skus'];

        if ($totalSkus === 0) {
            $output->writeln('<comment>No product SKUs found in catalog.</comment>');
            return Command::SUCCESS;
        }

        $minLen = $analysis['min_len'];
        $maxLen = $analysis['max_len'];
        $avgLen = $analysis['avg_len'];
        $hasUnicode = $analysis['has_unicode'];
        $hasControlChars = $analysis['has_control_chars'];
        $controlCharExamples = $analysis['control_char_examples'];
        $specialCharsMap = $analysis['special_chars_map'];

        // Render Summary Table
        $table = new Table($output);
        $table->setHeaders(['Metric', 'Analysis Result']);
        $table->addRows([
            ['Total SKUs Inspected', (string)$totalSkus],
            ['Shortest SKU Length', (string)$minLen],
            ['Longest SKU Length', (string)$maxLen],
            ['Average SKU Length', (string)$avgLen],
            ['Unicode / Non-ASCII Characters', $hasUnicode ? 'YES (Present in catalog)' : 'NO (Pure ASCII)'],
            ['ASCII Control Characters', $hasControlChars ? 'ALERT: Control chars detected!' : 'CLEAN (None detected)'],
        ]);
        $table->render();

        // Print Special Characters breakdown
        if (!empty($specialCharsMap)) {
            $uniqueCharsList = array_keys($specialCharsMap);
            $formattedChars = implode(' ', array_map(function ($c) {
                return $c === ' ' ? '[SPACE]' : $c;
            }, $uniqueCharsList));

            $output->writeln('');
            $output->writeln(sprintf(
                '<info>Unique Non-Alphanumeric Characters Found (%d):</info>',
                count($uniqueCharsList)
            ));
            $output->writeln('  ' . $formattedChars);
        } else {
            $output->writeln('');
            $output->writeln('<info>No special characters found. Catalog uses strictly alphanumeric SKUs.</info>');
        }

        if ($hasControlChars) {
            $output->writeln('');
            $output->writeln('<error>WARNING: Catalog contains SKUs with control characters. Examples:</error>');
            foreach ($controlCharExamples as $example) {
                $output->writeln('  - ' . $example);
            }
        }

        // Recommend Zod Regex
        $output->writeln('');
        $output->writeln('<info>Recommended Storefront Zod Regex Constraint:</info>');
        $output->writeln('  ' . $analysis['recommended_regex']);
        $output->writeln('  Explanation: Rejects dangerous ASCII control and null bytes while fully supporting');
        $output->writeln('  catalog spaces, symbols, and Unicode characters without false-positive rejects.');

        return Command::SUCCESS;
    }
}
