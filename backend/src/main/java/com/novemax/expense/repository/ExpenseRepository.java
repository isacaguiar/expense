package com.novemax.expense.repository;

import com.novemax.expense.model.Expense;
import com.novemax.expense.model.User;
import java.util.List;
import org.springframework.data.jpa.repository.JpaRepository;

import java.util.UUID;

public interface ExpenseRepository extends JpaRepository<Expense, UUID> {

  // Retorna despesas associadas a um grupo de partilha
  List<Expense> findByGroup_Id(UUID groupId);

  // Retorna despesas em que o usuário é um dos pagadores
  List<Expense> findByPayersContaining(User user);

  // Retorna despesas de um grupo de partilha em que o usuário é pagador
  List<Expense> findByGroup_IdAndPayersContaining(UUID groupId, User user);
}