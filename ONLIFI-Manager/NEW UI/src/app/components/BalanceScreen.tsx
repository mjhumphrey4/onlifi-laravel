import { Send, ArrowDownLeft, ArrowUpRight, Copy, RefreshCw, Check, Smartphone } from "lucide-react";
import { useState } from "react";

const transactions = [
  { id: 1, label: "Voucher Revenue", type: "credit", amount: "+MWK 45,000", date: "Today, 4:12 PM", icon: ArrowDownLeft, color: "#00E5A0" },
  { id: 2, label: "Airtel Withdrawal", type: "debit", amount: "-MWK 100,000", date: "Today, 10:30 AM", icon: ArrowUpRight, color: "#FF4757" },
  { id: 3, label: "Voucher Revenue", type: "credit", amount: "+MWK 27,500", date: "Yesterday, 6:45 PM", icon: ArrowDownLeft, color: "#00E5A0" },
  { id: 4, label: "Voucher Revenue", type: "credit", amount: "+MWK 15,000", date: "Yesterday, 2:20 PM", icon: ArrowDownLeft, color: "#00E5A0" },
  { id: 5, label: "TNM Withdrawal", type: "debit", amount: "-MWK 50,000", date: "Mon, 11:00 AM", icon: ArrowUpRight, color: "#FF4757" },
];

export function BalanceScreen() {
  const [copied, setCopied] = useState(false);
  const [withdrawAmount, setWithdrawAmount] = useState("");
  const [selectedNetwork, setSelectedNetwork] = useState("airtel");
  const [phone, setPhone] = useState("");

  const handleCopy = () => {
    setCopied(true);
    setTimeout(() => setCopied(false), 2000);
  };

  return (
    <div className="flex flex-col h-full bg-[#0A0F1E] overflow-y-auto">
      {/* Header */}
      <div className="px-5 pt-6 pb-4">
        <p className="text-[#8A94A6] text-xs tracking-widest uppercase">Finances</p>
        <h1 className="text-white" style={{ fontSize: "20px", fontWeight: 700, lineHeight: "1.3" }}>Mobile Money</h1>
      </div>

      {/* Balance Card */}
      <div className="mx-5 mb-5 rounded-2xl overflow-hidden" style={{ background: "linear-gradient(135deg, #00A86B 0%, #007A4D 60%, #004D31 100%)" }}>
        <div className="p-5">
          <div className="flex items-center justify-between mb-5">
            <div className="flex items-center gap-2">
              <Smartphone size={16} className="text-white/80" />
              <span className="text-white/80 text-xs">Airtel Money</span>
            </div>
            <button onClick={() => {}} className="flex items-center gap-1.5 bg-white/20 rounded-full px-3 py-1">
              <RefreshCw size={11} className="text-white" />
              <span className="text-white text-xs">Refresh</span>
            </button>
          </div>
          <p className="text-white/60 text-xs mb-1">Current Balance</p>
          <p className="text-white" style={{ fontSize: "32px", fontWeight: 800, letterSpacing: "-0.5px", lineHeight: 1 }}>
            MWK 284,500
          </p>
          <div className="mt-4 pt-4 border-t border-white/20 flex items-center justify-between">
            <div>
              <p className="text-white/60 text-xs">Account Number</p>
              <p className="text-white text-sm font-medium mt-0.5">0888 123 456</p>
            </div>
            <button
              onClick={handleCopy}
              className="flex items-center gap-1.5 bg-white/20 rounded-xl px-3 py-1.5"
            >
              {copied ? <Check size={12} className="text-[#00E5A0]" /> : <Copy size={12} className="text-white" />}
              <span className="text-white text-xs">{copied ? "Copied" : "Copy"}</span>
            </button>
          </div>
        </div>
        <div className="h-1 bg-gradient-to-r from-[#00E5A0]/40 via-[#00E5A0]/80 to-[#00E5A0]/40" />
      </div>

      {/* Quick Stats */}
      <div className="px-5 mb-5 grid grid-cols-2 gap-3">
        <div className="bg-[#151C30] rounded-2xl p-4">
          <p className="text-[#8A94A6] text-xs mb-1">This Month In</p>
          <p className="text-[#00E5A0] font-bold" style={{ fontSize: "16px" }}>+MWK 342,000</p>
        </div>
        <div className="bg-[#151C30] rounded-2xl p-4">
          <p className="text-[#8A94A6] text-xs mb-1">This Month Out</p>
          <p className="text-[#FF4757] font-bold" style={{ fontSize: "16px" }}>-MWK 150,000</p>
        </div>
      </div>

      {/* Withdraw Form */}
      <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
        <div className="flex items-center gap-2 mb-4">
          <div className="w-8 h-8 rounded-xl bg-[#0066FF]/20 flex items-center justify-center">
            <Send size={14} className="text-[#0066FF]" />
          </div>
          <h3 className="text-white" style={{ fontSize: "14px", fontWeight: 600 }}>Withdraw Funds</h3>
        </div>

        {/* Network Select */}
        <p className="text-[#8A94A6] text-xs mb-2">Select Network</p>
        <div className="flex gap-2 mb-4">
          {[
            { key: "airtel", label: "Airtel Money", color: "#FF0000" },
            { key: "tnm", label: "TNM Mpamba", color: "#007BFF" },
          ].map((net) => (
            <button
              key={net.key}
              onClick={() => setSelectedNetwork(net.key)}
              className={`flex-1 py-2.5 rounded-xl text-xs font-medium transition-all border ${
                selectedNetwork === net.key
                  ? "border-[#0066FF] bg-[#0066FF]/10 text-white"
                  : "border-[#1E2A45] bg-transparent text-[#8A94A6]"
              }`}
            >
              {net.label}
            </button>
          ))}
        </div>

        {/* Phone Number */}
        <p className="text-[#8A94A6] text-xs mb-2">Phone Number</p>
        <div className="flex items-center gap-2 bg-[#0A0F1E] rounded-xl px-3 py-2.5 mb-4">
          <span className="text-[#8A94A6] text-sm">🇲🇼 +265</span>
          <div className="w-px h-4 bg-[#1E2A45]" />
          <input
            value={phone}
            onChange={(e) => setPhone(e.target.value)}
            placeholder="888 123 456"
            className="bg-transparent text-white text-sm outline-none w-full placeholder-[#3A4560]"
          />
        </div>

        {/* Amount */}
        <p className="text-[#8A94A6] text-xs mb-2">Amount (MWK)</p>
        <div className="bg-[#0A0F1E] rounded-xl px-3 py-2.5 mb-4">
          <input
            value={withdrawAmount}
            onChange={(e) => setWithdrawAmount(e.target.value)}
            placeholder="0.00"
            className="bg-transparent text-white text-sm outline-none w-full placeholder-[#3A4560]"
          />
        </div>

        {/* Quick amounts */}
        <div className="flex gap-2 mb-4">
          {["10,000", "25,000", "50,000", "100,000"].map((amt) => (
            <button
              key={amt}
              onClick={() => setWithdrawAmount(amt)}
              className="flex-1 py-1.5 rounded-lg bg-[#0A0F1E] text-[#8A94A6] text-xs border border-[#1E2A45] hover:border-[#0066FF] hover:text-white transition-colors"
            >
              {amt}
            </button>
          ))}
        </div>

        <button className="w-full py-3 rounded-xl bg-[#0066FF] text-white text-sm font-semibold flex items-center justify-center gap-2">
          <Send size={14} />
          Initiate Withdrawal
        </button>
      </div>

      {/* Transaction History */}
      <div className="mx-5 mb-5 bg-[#151C30] rounded-2xl p-4">
        <h3 className="text-white mb-3" style={{ fontSize: "14px", fontWeight: 600 }}>Transaction History</h3>
        <div className="space-y-1">
          {transactions.map((tx) => (
            <div key={tx.id} className="flex items-center gap-3 py-2.5 border-b border-[#0A0F1E] last:border-0">
              <div className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0" style={{ background: `${tx.color}20` }}>
                <tx.icon size={14} style={{ color: tx.color }} />
              </div>
              <div className="flex-1">
                <p className="text-white text-xs font-medium">{tx.label}</p>
                <p className="text-[#8A94A6] text-xs">{tx.date}</p>
              </div>
              <span className="text-xs font-semibold" style={{ color: tx.color }}>{tx.amount}</span>
            </div>
          ))}
        </div>
      </div>
    </div>
  );
}
