<?php

namespace App\Imports;

use App\Models\MohDispenseItem;
use App\Models\Product;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Carbon\Carbon;

class MohDispenseImport implements ToModel, WithHeadingRow, WithValidation, WithChunkReading, SkipsEmptyRows
{
    protected $mohDispenseId;

    public function __construct($mohDispenseId)
    {
        $this->mohDispenseId = $mohDispenseId;
    }

    public function chunkSize(): int
    {
        return 100;
    }

    public function model(array $row)
    {
        $quantity = (int) ($row['quantity'] ?? 0);

        // Allow template rows that are not dispensed yet (empty quantity)
        if ($quantity <= 0) {
            return null;
        }

        // Find product by name, id, or productID
        $product = Product::where('name', $row['item'])
            ->orWhere('id', $row['item'])
            ->orWhere('productID', $row['item'])
            ->first();

        if (!$product) {
            throw new \Exception("Product not found: " . $row['item']);
        }

        return new MohDispenseItem([
            'moh_dispense_id' => $this->mohDispenseId,
            'product_id' => $product->id,
            'batch_no' => (string) ($row['batch_no'] ?? ''),
            'expiry_date' => $this->parseDate($row['expiry_date']),
            'quantity' => $quantity,
            'dispense_date' => $this->parseDate($row['dispense_date']),
            'dispensed_by' => $row['dispensed_by'] ?? '',
        ]);
    }

    public function rules(): array
    {
        return [
            // Only rows with quantity > 0 are treated as dispense rows.
            'quantity' => 'nullable|integer|min:1',
            'item' => 'required_with:quantity|string',
            'batch_no' => 'required_with:quantity',
            'expiry_date' => 'required_with:quantity',
            'dispense_date' => 'required_with:quantity',
            'dispensed_by' => 'required_with:quantity|string|max:255',
        ];
    }

    public function customValidationAttributes(): array
    {
        return [
            'item' => 'Item',
            'batch_no' => 'Batch No',
            'expiry_date' => 'Expiry Date',
            'quantity' => 'Quantity',
            'dispense_date' => 'Dispense Date',
            'dispensed_by' => 'Dispensed By',
        ];
    }

    public function customValidationMessages(): array
    {
        return [
            'quantity.integer' => 'Quantity must be a whole number.',
            'quantity.min' => 'Quantity must be greater than 0.',
            'item.required_with' => 'Item is required when Quantity is provided.',
            'batch_no.required_with' => 'Batch No is required when Quantity is provided.',
            'expiry_date.required_with' => 'Expiry Date is required when Quantity is provided.',
            'dispense_date.required_with' => 'Dispense Date is required when Quantity is provided.',
            'dispensed_by.required_with' => 'Dispensed By is required when Quantity is provided.',
            'dispensed_by.max' => 'Dispensed By must not exceed 255 characters.',
        ];
    }

    private function parseDate($date)
    {
        if (empty($date)) {
            throw new \Exception("Date field is required");
        }

        // Handle Excel date serial numbers
        if (is_numeric($date)) {
            try {
                return Carbon::createFromFormat('Y-m-d', \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($date)->format('Y-m-d'));
            } catch (\Exception $e) {
                return Carbon::createFromFormat('Y-m-d', gmdate('Y-m-d', ($date - 25569) * 86400));
            }
        }

        // Handle string dates
        $dateString = trim($date);
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];

        foreach ($formats as $format) {
            try {
                $parsedDate = Carbon::createFromFormat($format, $dateString);
                if ($parsedDate) {
                    return $parsedDate;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Try Carbon's flexible parsing
        try {
            return Carbon::parse($dateString);
        } catch (\Exception $e) {
            throw new \Exception("Unable to parse date: {$date}. Please use format YYYY-MM-DD");
        }
    }
}