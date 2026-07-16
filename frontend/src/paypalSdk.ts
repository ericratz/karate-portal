// Dynamic loader + minimal typings for the PayPal JS SDK. The old pay.php
// loaded the SDK with a plain <script> tag; the SPA injects the same tag on
// demand (CSP already allows www.paypal.com). Resolves null when the SDK
// can't load — unconfigured client id, content blocker, offline — and the
// page shows the same warning the PHP page did.

export interface PayPalButtonsInstance {
  render(container: HTMLElement): Promise<void>;
  close?(): Promise<void>;
}

export interface PayPalNamespace {
  Buttons(options: {
    style?: { layout?: string; shape?: string };
    createOrder: () => Promise<string>;
    onApprove: (data: { orderID: string }) => Promise<void>;
    onError: (err: unknown) => void;
  }): PayPalButtonsInstance;
}

declare global {
  interface Window {
    paypal?: PayPalNamespace;
  }
}

let sdkPromise: Promise<PayPalNamespace | null> | null = null;

export function loadPayPalSdk(clientId: string): Promise<PayPalNamespace | null> {
  if (!clientId) return Promise.resolve(null);
  if (window.paypal) return Promise.resolve(window.paypal);
  if (!sdkPromise) {
    sdkPromise = new Promise((resolve) => {
      const script = document.createElement('script');
      script.src = `https://www.paypal.com/sdk/js?client-id=${encodeURIComponent(clientId)}&currency=USD`;
      script.onload = () => resolve(window.paypal ?? null);
      script.onerror = () => {
        sdkPromise = null; // allow a retry on next mount
        resolve(null);
      };
      document.head.appendChild(script);
    });
  }
  return sdkPromise;
}
