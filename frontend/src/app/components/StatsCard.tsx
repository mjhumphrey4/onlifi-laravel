import { LucideIcon } from 'lucide-react';
import { Link } from 'react-router';

interface StatsCardProps {
  title: string;
  value: string;
  icon: LucideIcon;
  trend?: {
    value: string;
    isPositive: boolean;
  };
  note?: string;
  action?: {
    label: string;
    to: string;
  };
}

export function StatsCard({ title, value, icon: Icon, trend, note, action }: StatsCardProps) {
  return (
    <div className="bg-card border border-border rounded-lg p-4 sm:p-6 hover:border-primary/50 transition-colors">
      <div className="flex items-start justify-between">
        <div className="flex-1 min-w-0">
          <p className="text-xs sm:text-sm text-muted-foreground mb-1">{title}</p>
          <h3 className="text-2xl sm:text-3xl text-card-foreground mb-2">{value}</h3>
          {trend && (
            <p className={`text-xs sm:text-sm ${trend.isPositive ? 'text-primary' : 'text-destructive'}`}>
              {trend.isPositive ? 'Up' : 'Down'} {trend.value}
            </p>
          )}
          {note && <p className="text-xs text-muted-foreground mt-1">{note}</p>}
          {action && (
            <Link
              to={action.to}
              className="mt-3 inline-flex items-center rounded-md border border-border px-2.5 py-1.5 text-xs font-medium text-primary hover:bg-muted transition-colors"
            >
              {action.label}
            </Link>
          )}
        </div>
        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-primary/10 rounded-lg flex items-center justify-center flex-shrink-0">
          <Icon className="w-5 h-5 sm:w-6 sm:h-6 text-primary" />
        </div>
      </div>
    </div>
  );
}
