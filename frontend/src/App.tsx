import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import LoginPage from './pages/LoginPage';
import AcceptInvitePage from './pages/AcceptInvitePage';
import Dashboard from './pages/Dashboard';
import GroupForm from './pages/GroupForm';
import GroupMembersForm from './pages/GroupMembersForm';
import ExpenseManager from './pages/ExpenseManager';
import ExpenseForm from './pages/ExpenseForm';
import ExpenseView from './pages/ExpenseView';
import ExpensesEntry from './pages/ExpensesEntry';
import GroupSummary from './pages/GroupSummary';
import SummaryEntry from './pages/SummaryEntry';
import Payments from './pages/Payments';
import PaymentsEntry from './pages/PaymentsEntry';
import MembersEntry from './pages/MembersEntry';
import ReportsEntry from './pages/ReportsEntry';
import GroupReports from './pages/GroupReports';
import Profile from './pages/Profile';
import ChangePassword from './pages/ChangePassword';
import RequireAuth from './components/RequireAuth';
import GroupShellLayout from './layouts/GroupShellLayout';
import SimpleShellLayout from './layouts/SimpleShellLayout';

const App = () => {
  return (
    <Routes>
      <Route path="/" element={<LoginPage />} />
      <Route path="/aceitar-convite" element={<AcceptInvitePage />} />

      {/* Rotas privadas */}
      <Route element={<RequireAuth />}>
        {/* Com grupo selecionado: sidebar/navegação de grupo (GroupShellLayout) */}
        <Route element={<GroupShellLayout />}>
          <Route path="/groups/:id/summary" element={<GroupSummary />} />
          <Route path="/groups/:id/payments" element={<Payments />} />
          <Route path="/groups/:id/expenses" element={<ExpenseManager />} />
          <Route path="/groups/:id/expenses/new" element={<ExpenseForm />} />
          <Route path="/groups/:id/expenses/:expenseId" element={<ExpenseView />} />
          <Route path="/groups/:id/members" element={<GroupMembersForm />} />
          <Route path="/groups/:id/reports" element={<GroupReports />} />
          <Route path="/groups/:id/edit" element={<GroupForm />} />
        </Route>

        {/* Sem grupo selecionado: cabeçalho simples (SimpleShellLayout) */}
        <Route element={<SimpleShellLayout />}>
          <Route path="/meus-grupos" element={<Dashboard />} />
          <Route path="/dashboard" element={<Navigate to="/meus-grupos" replace />} />
          <Route path="/groups/new" element={<GroupForm />} />
          <Route path="/expenses" element={<ExpensesEntry />} />
          <Route path="/summary" element={<SummaryEntry />} />
          <Route path="/payments" element={<PaymentsEntry />} />
          <Route path="/members" element={<MembersEntry />} />
          <Route path="/reports" element={<ReportsEntry />} />
          <Route path="/profile" element={<Profile />} />
          <Route path="/change-password" element={<ChangePassword />} />
        </Route>
      </Route>

      {/* rota “catch-all” opcional */}
      <Route path="*" element={<h2>404: Página não encontrada</h2>} />
    </Routes>
  );
};

export default App;
