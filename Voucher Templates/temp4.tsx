import { Key, Clock, Wifi } from 'lucide-react';

interface VoucherProps {
  num: number;
  wifiName: string;
  username: string;
  validity: string;
  timelimit: string;
  price: string;
  helptext: string;
  showQR?: boolean;
}

const Voucher = ({ num, wifiName, username, validity, timelimit, price, helptext, showQR = false }: VoucherProps) => {
  return (
    <div
      className="relative bg-white border-2 border-[#0444cf] overflow-hidden shadow-lg"
      style={{
        width: '240px',
        height: '180px',
        pageBreakInside: 'avoid',
        fontFamily: 'Arial, sans-serif'
      }}
    >
      {/* Decorative corner accent */}
      <div className="absolute top-0 right-0 w-20 h-20 bg-gradient-to-br from-blue-400/20 to-purple-400/20 rounded-bl-full"></div>

      {/* Header */}
      <div className="relative bg-gradient-to-r from-[#0444cf] to-[#0666ff] text-white px-3 py-2.5 flex items-center justify-between">
        <div className="flex items-center gap-2">
          <Wifi className="w-4 h-4" strokeWidth={2.5} />
          <span className="font-bold tracking-wide" style={{ fontSize: '14px' }}>STK WIFI POINT</span>
        </div>
        <span
          className="bg-white/25 px-2.5 py-0.5 rounded backdrop-blur-sm font-semibold"
          style={{ fontSize: '10px' }}
        >
          #{String(num).padStart(4, '0')}
        </span>
      </div>

      {/* Body */}
      <div className="px-3 pt-4 pb-12 relative">
        {/* User Code Section */}
        <div className="flex items-center gap-2 mb-3 bg-gradient-to-r from-blue-50 to-purple-50 rounded px-2.5 py-2.5 border border-blue-200">
          <Key className="w-6 h-6 text-blue-600 flex-shrink-0" strokeWidth={2.5} />
          <div
            className="font-bold text-blue-900 tracking-wide break-all"
            style={{ fontSize: '18px' }}
          >
            {username}
          </div>
        </div>

        {/* Price and Duration Row */}
        <div className="flex gap-4 mb-3" style={{ fontSize: '12px' }}>
          <div className="flex items-start gap-1.5 text-gray-700">
            <span className="font-semibold text-gray-900">Price:</span>
            <span className="text-green-700 font-bold">{price}</span>
          </div>
          <div className="flex items-start gap-1.5 text-gray-700">
            <span className="font-semibold text-gray-900">Duration:</span>
            <span className="text-blue-700 font-medium">{timelimit}</span>
          </div>
        </div>

        {/* QR Code Section */}
        {showQR && (
          <div className="text-center my-2 p-1 bg-gray-50 rounded border border-gray-200">
            <div className="w-20 h-20 bg-white border border-gray-300 mx-auto flex items-center justify-center text-xs text-gray-400">
              QR
            </div>
          </div>
        )}

        {/* Validity Info */}
        <div className="space-y-1.5" style={{ fontSize: '12px' }}>
          <div className="flex items-center gap-1.5 text-gray-700">
            <Clock className="w-3.5 h-3.5 text-purple-600 flex-shrink-0" />
            <span className="text-gray-900">{validity}</span>
          </div>

          <div className="text-gray-700 mt-2">
            <span className="font-semibold text-gray-900">WiFi:</span>
            <span className="text-emerald-700 font-bold ml-1.5">{wifiName}</span>
          </div>
        </div>
      </div>

      {/* Footer */}
      <div
        className="absolute bottom-0 left-0 right-0 bg-gradient-to-r from-gray-100 to-gray-50 px-3 py-1.5 border-t border-gray-300"
        style={{ fontSize: '9px', lineHeight: '1.3' }}
      >
        <div className="text-gray-700 font-medium">{helptext}</div>
        <div className="flex items-center justify-between mt-0.5">
          <div className="text-gray-900 font-bold">Support: +256 700 000 000</div>
          <div className="text-gray-600 font-medium">Powered by: <span className="text-blue-700 font-bold">onlfi.net</span></div>
        </div>
      </div>
    </div>
  );
};

export default function App() {
  // Single voucher example
  const sampleVoucher = {
    num: 1,
    wifiName: 'STK-GUEST-WIFI',
    username: 'USER001XK9P',
    validity: 'Valid: 30 Days from activation',
    timelimit: '24 Hours',
    price: 'UGX 5,000',
    helptext: 'Terms apply. One device per voucher.',
    showQR: false
  };

  return (
    <div className="min-h-screen bg-gray-100 flex items-center justify-center p-8">
      <Voucher {...sampleVoucher} />
    </div>
  );
}