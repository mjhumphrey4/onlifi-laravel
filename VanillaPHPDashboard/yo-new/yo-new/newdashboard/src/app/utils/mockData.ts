export type TransactionStatus = 'success' | 'pending' | 'failed';
export type TransactionType = 'deposit' | 'withdrawal';

export interface Transaction {
  id: string;
  amount: number;
  type: TransactionType;
  status: TransactionStatus;
  date: Date;
  customer: string;
  reference: string;
  hasVoucher: boolean;
}

// Generate random transactions
const generateTransactions = (count: number): Transaction[] => {
  const statuses: TransactionStatus[] = ['success', 'pending', 'failed'];
  const types: TransactionType[] = ['deposit', 'withdrawal'];
  const customers = [
    'John Doe', 'Jane Smith', 'Michael Brown', 'Sarah Johnson', 
    'David Wilson', 'Emily Davis', 'Robert Taylor', 'Lisa Anderson',
    'James Martinez', 'Maria Garcia', 'William Rodriguez', 'Jennifer Lee'
  ];

  return Array.from({ length: count }, (_, i) => {
    const date = new Date();
    date.setDate(date.getDate() - Math.floor(Math.random() * 30));
    date.setHours(Math.floor(Math.random() * 24));
    date.setMinutes(Math.floor(Math.random() * 60));
    const status = statuses[Math.floor(Math.random() * statuses.length)];

    return {
      id: `TXN${String(i + 1).padStart(6, '0')}`,
      amount: Math.floor(Math.random() * 5000) + 100,
      type: types[Math.floor(Math.random() * types.length)],
      status,
      date,
      customer: customers[Math.floor(Math.random() * customers.length)],
      reference: `REF${Math.random().toString(36).substring(2, 10).toUpperCase()}`,
      hasVoucher: status === 'success' ? Math.random() > 0.3 : false,
    };
  });
};

export const allTransactions = generateTransactions(150);

// Get transactions by status
export const getTransactionsByStatus = (status: TransactionStatus) => {
  return allTransactions.filter(t => t.status === status);
};

// Get withdrawal transactions
export const getWithdrawals = () => {
  return allTransactions.filter(t => t.type === 'withdrawal');
};

// Get today's earnings
export const getTodayEarnings = () => {
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  
  return allTransactions
    .filter(t => {
      const txDate = new Date(t.date);
      txDate.setHours(0, 0, 0, 0);
      return txDate.getTime() === today.getTime() && 
             t.status === 'success' && 
             t.type === 'deposit';
    })
    .reduce((sum, t) => sum + t.amount, 0);
};

// Get total earnings
export const getTotalEarnings = () => {
  return allTransactions
    .filter(t => t.status === 'success' && t.type === 'deposit')
    .reduce((sum, t) => sum + t.amount, 0);
};

// Get total withdrawals
export const getTotalWithdrawals = () => {
  return allTransactions
    .filter(t => t.status === 'success' && t.type === 'withdrawal')
    .reduce((sum, t) => sum + t.amount, 0);
};

// Get recent transactions (last 10)
export const getRecentTransactions = () => {
  return [...allTransactions]
    .sort((a, b) => b.date.getTime() - a.date.getTime())
    .slice(0, 10);
};

// Get weekly performance data
export const getWeeklyPerformance = (weekStart: Date) => {
  const weekData = [];
  
  for (let i = 0; i < 7; i++) {
    const date = new Date(weekStart);
    date.setDate(date.getDate() + i);
    date.setHours(0, 0, 0, 0);
    
    const nextDay = new Date(date);
    nextDay.setDate(nextDay.getDate() + 1);
    
    const dayTotal = allTransactions
      .filter(t => {
        const txDate = new Date(t.date);
        return txDate >= date && 
               txDate < nextDay && 
               t.status === 'success' && 
               t.type === 'deposit';
      })
      .reduce((sum, t) => sum + t.amount, 0);
    
    weekData.push({
      date,
      day: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][date.getDay()],
      amount: dayTotal,
    });
  }
  
  return weekData;
};

// Get current week start (Monday)
export const getCurrentWeekStart = () => {
  const now = new Date();
  const day = now.getDay();
  const diff = now.getDate() - day + (day === 0 ? -6 : 1); // Adjust to Monday
  const monday = new Date(now.setDate(diff));
  monday.setHours(0, 0, 0, 0);
  return monday;
};

// Get monthly performance data
export const getMonthlyPerformance = (monthStart: Date) => {
  const monthData = [];
  const year = monthStart.getFullYear();
  const month = monthStart.getMonth();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  
  for (let i = 1; i <= daysInMonth; i++) {
    const date = new Date(year, month, i);
    date.setHours(0, 0, 0, 0);
    
    const nextDay = new Date(date);
    nextDay.setDate(nextDay.getDate() + 1);
    
    const dayTotal = allTransactions
      .filter(t => {
        const txDate = new Date(t.date);
        return txDate >= date && 
               txDate < nextDay && 
               t.status === 'success' && 
               t.type === 'deposit';
      })
      .reduce((sum, t) => sum + t.amount, 0);
    
    monthData.push({
      date,
      day: i,
      amount: dayTotal,
    });
  }
  
  return monthData;
};

// Get current month start
export const getCurrentMonthStart = () => {
  const now = new Date();
  return new Date(now.getFullYear(), now.getMonth(), 1);
};