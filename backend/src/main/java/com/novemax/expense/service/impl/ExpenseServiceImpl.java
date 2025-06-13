package com.novemax.expense.service.impl;

import com.novemax.expense.dto.request.ExpenseRequestDTO;
import com.novemax.expense.dto.response.ExpenseResponseDTO;
import com.novemax.expense.mapper.ExpenseMapper;
import com.novemax.expense.model.Expense;
import com.novemax.expense.model.Group;
import com.novemax.expense.model.User;
import com.novemax.expense.repository.ExpenseRepository;
import com.novemax.expense.repository.GroupRepository;
import com.novemax.expense.repository.UserRepository;
import com.novemax.expense.service.ExpenseService;
import jakarta.transaction.Transactional;
import java.time.YearMonth;
import org.springframework.format.annotation.DateTimeFormat;
import org.springframework.http.ResponseEntity;
import org.springframework.stereotype.Service;

import java.util.*;
import java.util.stream.Collectors;
import org.springframework.web.bind.annotation.GetMapping;
import org.springframework.web.bind.annotation.PathVariable;

@Service
public class ExpenseServiceImpl implements ExpenseService {
  private final ExpenseRepository expenseRepository;
  private final UserRepository userRepository;
  private final GroupRepository groupRepository;
  private final ExpenseMapper expenseMapper;

  public ExpenseServiceImpl(ExpenseRepository expenseRepository,
                            UserRepository userRepository,
                            GroupRepository groupRepository,
                            ExpenseMapper expenseMapper) {
    this.expenseRepository = expenseRepository;
    this.userRepository = userRepository;
    this.groupRepository = groupRepository;
    this.expenseMapper = expenseMapper;
  }

  @Override
  @Transactional
  public ExpenseResponseDTO createExpense(String userEmail, ExpenseRequestDTO dto) {
    User creator = userRepository.findByEmail(userEmail).orElseThrow();
    User userPayer = userRepository.findById(dto.getUserPayerId()).orElseThrow();
    Group group = groupRepository.findById(dto.getGroupId()).orElseThrow();

    List<User> payers = userRepository.findAllById(dto.getPayerIds());

    Expense expense = new Expense();
    expense.setId(UUID.randomUUID());
    expense.setDescription(dto.getDescription());
    expense.setTotalValue(dto.getTotalValue());
    expense.setDatePayment(dto.getPaymentDate());
    expense.setExpenseType(dto.getType());
    expense.setInstallments(dto.getInstallments());
    expense.setUserCreator(creator);
    expense.setUserPayer(userPayer);
    expense.setGroup(group);
    expense.setPayers(payers);

    Expense saved = expenseRepository.save(expense);

    return expenseMapper.toResponseDTO(saved);
  }

  @Override
  public List<ExpenseResponseDTO> getExpensesByGroup(UUID groupId) {
    return expenseRepository.findByGroup_Id(groupId).stream()
        .map(expenseMapper::toResponseDTO)
        .collect(Collectors.toList());
  }

  @Override
  @Transactional
  public void deleteExpense(UUID expenseId, String userEmail) {
    Expense expense = expenseRepository.findById(expenseId)
        .orElseThrow(() -> new RuntimeException("Despesa não encontrada"));
    if (!expense.getUserCreator().getEmail().equals(userEmail)) {
      throw new RuntimeException("Usuário não autorizado para deletar esta despesa");
    }
    expenseRepository.delete(expense);
  }

  @Override
  public Object calculateBalanceForUser(String userEmail, UUID groupId) {
    User user = userRepository.findByEmail(userEmail).orElseThrow();
    List<Expense> expenses = expenseRepository.findByGroup_Id(groupId);

    double totalOwed = expenses.stream()
        .filter(e -> e.getPayers().contains(user))
        .mapToDouble(e -> e.getTotalValue().doubleValue() / e.getPayers().size())
        .sum();

    Map<String, Object> result = new HashMap<>();
    result.put("user", user.getEmail());
    result.put("totalOwed", totalOwed);
    return result;
  }

}

