import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/app/components/ui/card';
import { Button } from '@/app/components/ui/button';
import { Badge } from '@/app/components/ui/badge';
import { Alert, AlertDescription } from '@/app/components/ui/alert';
import { Input } from '@/app/components/ui/input';
import { Label } from '@/app/components/ui/label';
import { Textarea } from '@/app/components/ui/textarea';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/app/components/ui/dialog';
import { CheckCircle, XCircle, Clock, Mail, Calendar } from 'lucide-react';

interface Tenant {
  id: number;
  name: string;
  slug: string;
  domain: string | null;
  status: string;
  created_at: string;
  users: Array<{
    name: string;
    email: string;
  }>;
}

export default function TenantApproval() {
  const navigate = useNavigate();
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [loading, setLoading] = useState(true);
  const [selectedTenant, setSelectedTenant] = useState<Tenant | null>(null);
  const [showRejectDialog, setShowRejectDialog] = useState(false);
  const [rejectionReason, setRejectionReason] = useState('');
  const [processing, setProcessing] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);

  useEffect(() => {
    fetchPendingTenants();
  }, []);

  const fetchPendingTenants = async () => {
    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch('/api/super-admin/tenants/pending', {
        headers: {
          'Authorization': `Bearer ${token}`,
        },
      });

      if (!response.ok) {
        if (response.status === 401) {
          navigate('/admin/login');
          return;
        }
        throw new Error('Failed to fetch pending tenants');
      }

      const data = await response.json();
      setTenants(data.data || []);
    } catch (error) {
      console.error('Error fetching tenants:', error);
      setMessage({ type: 'error', text: 'Failed to load pending tenants' });
    } finally {
      setLoading(false);
    }
  };

  const handleApprove = async (tenant: Tenant) => {
    if (!confirm(`Are you sure you want to approve "${tenant.name}"? This will create their database and grant access.`)) {
      return;
    }

    setProcessing(true);
    setMessage(null);

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${tenant.id}/approve`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Approval failed');
      }

      setMessage({ type: 'success', text: `${tenant.name} has been approved successfully!` });
      fetchPendingTenants();
    } catch (error: any) {
      setMessage({ type: 'error', text: error.message || 'Failed to approve tenant' });
    } finally {
      setProcessing(false);
    }
  };

  const handleReject = async () => {
    if (!selectedTenant || !rejectionReason.trim()) {
      return;
    }

    setProcessing(true);
    setMessage(null);

    try {
      const token = localStorage.getItem('admin_token');
      const response = await fetch(`/api/super-admin/tenants/${selectedTenant.id}/reject`, {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ reason: rejectionReason }),
      });

      const data = await response.json();

      if (!response.ok) {
        throw new Error(data.message || 'Rejection failed');
      }

      setMessage({ type: 'success', text: `${selectedTenant.name} has been rejected` });
      setShowRejectDialog(false);
      setRejectionReason('');
      setSelectedTenant(null);
      fetchPendingTenants();
    } catch (error: any) {
      setMessage({ type: 'error', text: error.message || 'Failed to reject tenant' });
    } finally {
      setProcessing(false);
    }
  };

  const openRejectDialog = (tenant: Tenant) => {
    setSelectedTenant(tenant);
    setShowRejectDialog(true);
  };

  if (loading) {
    return (
      <div className="flex items-center justify-center h-screen">
        <div className="text-center">
          <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-indigo-600 mx-auto"></div>
          <p className="mt-4 text-gray-600">Loading pending approvals...</p>
        </div>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-3xl font-bold">Pending Tenant Approvals</h1>
          <p className="text-gray-600 mt-1">Review and approve new tenant signups</p>
        </div>
        <Button variant="outline" onClick={() => navigate('/admin/dashboard')}>
          Back to Dashboard
        </Button>
      </div>

      {message && (
        <Alert variant={message.type === 'error' ? 'destructive' : 'default'}>
          <AlertDescription>{message.text}</AlertDescription>
        </Alert>
      )}

      {tenants.length === 0 ? (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <Clock className="h-16 w-16 text-gray-400 mb-4" />
            <h3 className="text-xl font-semibold text-gray-700 mb-2">No Pending Approvals</h3>
            <p className="text-gray-500">All tenant signups have been processed</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {tenants.map((tenant) => (
            <Card key={tenant.id}>
              <CardHeader>
                <div className="flex justify-between items-start">
                  <div className="flex-1">
                    <CardTitle className="text-xl">{tenant.name}</CardTitle>
                    <CardDescription className="mt-2 space-y-1">
                      <div className="flex items-center gap-2">
                        <Mail className="h-4 w-4" />
                        <span>{tenant.users[0]?.email || 'No email'}</span>
                      </div>
                      <div className="flex items-center gap-2">
                        <Calendar className="h-4 w-4" />
                        <span>Signed up: {new Date(tenant.created_at).toLocaleDateString()}</span>
                      </div>
                      {tenant.domain && (
                        <div className="flex items-center gap-2">
                          <span className="text-sm">Domain: {tenant.domain}</span>
                        </div>
                      )}
                    </CardDescription>
                  </div>
                  <Badge variant="outline" className="ml-4">
                    <Clock className="h-3 w-3 mr-1" />
                    Pending
                  </Badge>
                </div>
              </CardHeader>
              <CardContent>
                <div className="flex gap-2">
                  <Button
                    onClick={() => handleApprove(tenant)}
                    disabled={processing}
                    className="flex-1"
                    variant="default"
                  >
                    <CheckCircle className="h-4 w-4 mr-2" />
                    Approve
                  </Button>
                  <Button
                    onClick={() => openRejectDialog(tenant)}
                    disabled={processing}
                    className="flex-1"
                    variant="destructive"
                  >
                    <XCircle className="h-4 w-4 mr-2" />
                    Reject
                  </Button>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      <Dialog open={showRejectDialog} onOpenChange={setShowRejectDialog}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Reject Tenant Application</DialogTitle>
            <DialogDescription>
              Please provide a reason for rejecting {selectedTenant?.name}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-4">
            <div className="space-y-2">
              <Label htmlFor="reason">Rejection Reason</Label>
              <Textarea
                id="reason"
                placeholder="Enter the reason for rejection..."
                value={rejectionReason}
                onChange={(e) => setRejectionReason(e.target.value)}
                rows={4}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setShowRejectDialog(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleReject}
              disabled={!rejectionReason.trim() || processing}
            >
              {processing ? 'Rejecting...' : 'Reject Tenant'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
