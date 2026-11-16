<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Order received</title>
</head>

<body style="margin: 0; padding: 24px; background: #f3f4f6; font-family: Arial, sans-serif; color: #1f2937;">
    <div style="max-width: 640px; margin: 0 auto; padding: 24px; background: #ffffff; border: 1px solid #e5e7eb;">

        <h1 style="margin-top: 0; font-size: 24px;">
            Order received
        </h1>

        <p>
            Thank you! We have received your order
            <strong>#{{ $order->id }}</strong>.
        </p>

        <p>
            You can follow your order status in the application.
        </p>

        <p style="color: #6b7280; font-size: 14px;">
            Order date: {{ $order->created_at->toIso8601String() }}
        </p>

        <h2 style="margin-top: 28px; font-size: 18px;">
            Order invoice
        </h2>

        <table width="100%" cellpadding="10" cellspacing="0"
               style="border-collapse: collapse; font-size: 14px;">
            <thead>
                <tr style="background: #f3f4f6;">
                    <th align="left">Product</th>
                    <th align="center">Quantity</th>
                    <th align="right">Unit price</th>
                    <th align="right">Subtotal</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($order->items as $item)
                    <tr>
                        <td style="border-bottom: 1px solid #e5e7eb;">
                            {{ $item->product_name }}
                        </td>

                        <td align="center"
                            style="border-bottom: 1px solid #e5e7eb;">
                            {{ $item->quantity }}
                        </td>

                        <td align="right"
                            style="border-bottom: 1px solid #e5e7eb;">
                            {{ $item->unit_price }}
                        </td>

                        <td align="right"
                            style="border-bottom: 1px solid #e5e7eb;">
                            {{ $item->subtotal }}
                        </td>
                    </tr>
                @endforeach
            </tbody>

            <tfoot>
                <tr>
                    <td colspan="3" align="right">
                        <strong>Total</strong>
                    </td>

                    <td align="right">
                        <strong>{{ $order->total_amount }}</strong>
                    </td>
                </tr>
            </tfoot>
        </table>

        <p style="margin-top: 28px; color: #6b7280; font-size: 13px;">
            Please keep this email for your records.
        </p>

    </div>
</body>
</html>
