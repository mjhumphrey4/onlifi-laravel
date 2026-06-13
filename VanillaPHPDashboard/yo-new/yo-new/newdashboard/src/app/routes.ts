import { createBrowserRouter } from 'react-router';
import { Layout } from './components/Layout';
import { Dashboard } from './pages/Dashboard';
import { Transactions } from './pages/Transactions';
import { Sites } from './pages/Sites';
import { Withdrawals } from './pages/Withdrawals';
import { SmsLogs } from './pages/SmsLogs';
import { AnalyzePerformance } from './pages/AnalyzePerformance';
import { PerformanceGraphs } from './pages/PerformanceGraphs';
import { VoucherStock } from './pages/VoucherStock';
import { ImportVouchers } from './pages/ImportVouchers';
import { MonitorVouchers } from './pages/MonitorVouchers';
import { CaptivePage } from './pages/CaptivePage';

export const router = createBrowserRouter([
  {
    path: '/',
    Component: Layout,
    children: [
      { index: true, Component: Dashboard },
      { path: 'sites', Component: Sites },
      { path: 'transactions', Component: Transactions },
      { path: 'withdrawals', Component: Withdrawals },
      { path: 'sms-logs', Component: SmsLogs },
      { path: 'performance', Component: AnalyzePerformance },
      { path: 'performance-graphs', Component: PerformanceGraphs },
      { path: 'voucher-stock', Component: VoucherStock },
      { path: 'import-vouchers', Component: ImportVouchers },
      { path: 'monitor-vouchers', Component: MonitorVouchers },
      { path: 'captive-page', Component: CaptivePage },
    ],
  },
]);
