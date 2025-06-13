package com.novemax.expense.service;

import com.novemax.expense.dto.request.ExpenseRequestDTO;
import com.novemax.expense.dto.response.ExpenseResponseDTO;
import java.util.List;
import java.util.UUID;

public interface ExpenseService {
  ExpenseResponseDTO createExpense(String userEmail, ExpenseRequestDTO dto);
  List<ExpenseResponseDTO> getExpensesByGroup(UUID groupId);
  Object calculateBalanceForUser(String userEmail, UUID groupId);

  void deleteExpense(UUID expenseId, String userEmail);
}