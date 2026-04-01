<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class PricingConfigController extends Controller
{
    protected $configPath;

    public function __construct()
    {
        $this->configPath = config_path('pricing.php');
    }

    protected function authorize()
    {
        if (!auth('admin')->user()->isSuperAdmin()) {
            abort(403, 'Only Super Admins can manage pricing configuration.');
        }
    }

    public function index()
    {
        $this->authorize();
        $pricing = config('pricing');
        return view('admin.settings.pricing', compact('pricing'));
    }

    public function update(Request $request)
    {
        $this->authorize();

        $request->validate([
            'default_markup'        => 'required|numeric|min:0|max:100',
            'minimum_markup'        => 'required|numeric|min:0|max:100',
            'maximum_markup'        => 'required|numeric|min:0|max:100',
            'currency_buffer'       => 'required|numeric|min:0|max:20',
            'round_prices'          => 'nullable|boolean',
            'service_type_markup'   => 'required|array',
            'service_type_markup.*' => 'numeric|min:0|max:100',
            'platform_markup'       => 'required|array',
            'platform_markup.*'     => 'numeric|min:0|max:100',
            'combined_markup'       => 'required|array',
            'combined_markup.*'     => 'numeric|min:0|max:100',
        ]);

        $current = config('pricing');

        $current['default_markup']  = (float) $request->default_markup;
        $current['minimum_markup']  = (float) $request->minimum_markup;
        $current['maximum_markup']  = (float) $request->maximum_markup;
        $current['currency_buffer'] = (float) $request->currency_buffer;
        $current['round_prices']    = $request->boolean('round_prices');

        foreach ($request->service_type_markup as $key => $val) {
            if (isset($current['service_type_markup'][$key])) {
                $current['service_type_markup'][$key] = (float) $val;
            }
        }

        foreach ($request->platform_markup as $key => $val) {
            if (isset($current['platform_markup'][$key])) {
                $current['platform_markup'][$key] = (float) $val;
            }
        }

        foreach ($request->combined_markup as $key => $val) {
            if (isset($current['combined_markup'][$key])) {
                $current['combined_markup'][$key] = (float) $val;
            }
        }

        $this->writeConfig($current);

        Artisan::call('config:clear');

        return redirect()->route('admin.pricing.index')
            ->with('success', 'Pricing configuration updated successfully!');
    }

    protected function writeConfig(array $config)
    {
        $content = "<?php\n\nreturn " . $this->varExport($config) . ";\n";
        file_put_contents($this->configPath, $content);
    }

    protected function varExport($var, $indent = "")
    {
        switch (gettype($var)) {
            case 'array':
                $indexed = array_keys($var) === range(0, count($var) - 1);
                $r = [];
                foreach ($var as $key => $value) {
                    $r[] = "    $indent" 
                        . ($indexed ? "" : $this->varExport($key) . " => ") 
                        . $this->varExport($value, "$indent    ");
                }
                return "[\n" . implode(",\n", $r) . "\n$indent]";
            case 'boolean':
                return $var ? 'true' : 'false';
            case 'NULL':
                return 'null';
            case 'string':
                return "'" . addslashes($var) . "'";
            default:
                return var_export($var, true);
        }
    }
}