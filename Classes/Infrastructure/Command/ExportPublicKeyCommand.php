<?php

declare(strict_types=1);

namespace Koh\ContentProvenance\Infrastructure\Command;

use Koh\ContentProvenance\Domain\Contract\KeyProviderInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'provenance:export-public-key',
    description: 'Export the current public key (PEM or JSON format)',
)]
final class ExportPublicKeyCommand extends Command
{
    public function __construct(
        private readonly KeyProviderInterface $keyProvider,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'format',
            'f',
            InputOption::VALUE_OPTIONAL,
            'Output format: json, pem, or base64 (raw base64-encoded key, suitable for piping into other tools)',
            'json',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $format = (string) $input->getOption('format');

        $keyPair = $this->keyProvider->getSigningKeyPair();

        switch ($format) {
            case 'pem':
                $publicKeyBytes = sodium_base642bin($keyPair->publicKey, SODIUM_BASE64_VARIANT_ORIGINAL);
                $pemContent = "-----BEGIN PUBLIC KEY-----\n"
                    . chunk_split(base64_encode($publicKeyBytes), 64, "\n")
                    . "-----END PUBLIC KEY-----\n";
                $output->write($pemContent);

                return Command::SUCCESS;

            case 'base64':
                // Raw base64 — exactly what receivers paste into their
                // SourceSite.public_key column. No trailing newline so
                // shell substitution (`$(... --format=base64)`) yields
                // the unmodified value.
                $output->write($keyPair->publicKey);

                return Command::SUCCESS;

            case 'json':
                $output->writeln(json_encode([
                    'keyId' => $keyPair->keyId,
                    'publicKey' => $keyPair->publicKey,
                    'algorithm' => 'Ed25519',
                    'createdAt' => $keyPair->createdAt->format(\DateTimeInterface::ATOM),
                    'expiresAt' => $keyPair->expiresAt?->format(\DateTimeInterface::ATOM),
                ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

                return Command::SUCCESS;

            default:
                $io->error(sprintf('Unknown format "%s". Use json, pem, or base64.', $format));

                return Command::INVALID;
        }
    }
}
