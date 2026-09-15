<?php

namespace Tests\Unit;

use App\Console\Commands\I18nLangFlattenCommand;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Pin: the published Laravel language arrays are catalogued in lang/en.json
 * as dotted keys (the translation pass carries them), the English line equals
 * the PHP value, and the framework resolves the JSON line first so a
 * translated line is what a resident reads. DB-free.
 */
class LangJsonFlattenTest extends TestCase
{
    #[Test]
    public function every_published_string_leaf_is_catalogued_in_lang_en_json(): void
    {
        $leaves = I18nLangFlattenCommand::leaves(base_path('lang/en'));
        $this->assertGreaterThan(100, count($leaves), 'lang/en/*.php published (php artisan lang:publish)');

        $json = json_decode((string) file_get_contents(base_path('lang/en.json')), true, 512, JSON_THROW_ON_ERROR);
        $missing = [];
        foreach ($leaves as $key => $text) {
            if (($json[$key] ?? null) !== $text) {
                $missing[] = $key;
            }
        }
        $this->assertSame([], $missing, 'run php artisan i18n:lang-flatten');
        $this->assertArrayHasKey('validation.required', $json);
        $this->assertArrayNotHasKey('validation.custom.attribute-name.rule-name', $json);
        $this->assertArrayHasKey('validation.between.array', $json);
    }

    #[Test]
    public function the_json_line_wins_over_the_php_array(): void
    {
        $php = require base_path('lang/en/validation.php');
        $this->assertSame($php['required'], __('validation.required'));

        // The app's own lang/en.json is merged last, so a JSON line it lacks
        // proves the order: validation.custom.* stays in the PHP file only.
        $key = 'validation.custom.attribute-name.rule-name';
        $this->assertSame('custom-message', __($key));
        $tmp = sys_get_temp_dir().'/cga_lang_'.bin2hex(random_bytes(4));
        mkdir($tmp);
        file_put_contents($tmp.'/en.json', json_encode([$key => 'JSON line wins']));
        try {
            Lang::addJsonPath($tmp);
            Lang::setLoaded([]);
            $this->assertSame('JSON line wins', __($key));
        } finally {
            unlink($tmp.'/en.json');
            rmdir($tmp);
        }
    }
}
